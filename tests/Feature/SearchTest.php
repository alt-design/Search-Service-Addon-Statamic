<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

uses(PreventsSavingStacheItemsToDisk::class);

beforeEach(function () {
    config([
        'search-service.url' => 'https://search.test',
        'search-service.key' => '1|secret',
    ]);

    Queue::fake();

    Collection::make('articles')->title('Articles')->routes('/articles/{slug}')->save();

    Entry::make()->collection('articles')->id('sofa')->slug('sofa')->published(true)
        ->data(['title' => 'Sofa Beds'])->save();
    Entry::make()->collection('articles')->id('chair')->slug('chair')->published(true)
        ->data(['title' => 'Armchairs'])->save();
    Entry::make()->collection('articles')->id('draft')->slug('draft')->published(false)
        ->data(['title' => 'Draft Sofa'])->save();
});

it('returns hydrated results in the order the service gave them', function () {
    Http::fake(['search.test/api/search*' => Http::response(['total' => 2, 'match' => 'exact', 'results' => [
        ['reference' => 'chair', 'score' => 8.1],
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/search?q=sofa&limit=5')
        ->assertOk()
        ->assertExactJson([
            'query' => 'sofa',
            'limit' => 5,
            'offset' => 0,
            'total' => 2,
            'match' => 'exact',
            'corrected' => null,
            'results' => [
                ['reference' => 'chair', 'score' => 8.1, 'title' => 'Armchairs', 'url' => '/articles/chair', 'collection' => 'articles'],
                ['reference' => 'sofa', 'score' => 12.5, 'title' => 'Sofa Beds', 'url' => '/articles/sofa', 'collection' => 'articles'],
            ],
        ]);
});

it('reports the match mode the service used', function () {
    Http::fake(['search.test/api/search*' => Http::response(['total' => 1, 'match' => 'corrected', 'corrected' => 'sofa', 'results' => [
        ['reference' => 'sofa', 'score' => 0.8],
    ]])]);

    $this->getJson('/!/search-service/search?q=sofra')
        ->assertOk()
        ->assertJsonPath('match', 'corrected');
});

it('defaults the match mode to exact when the service omits it', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/search?q=sofa')
        ->assertOk()
        ->assertJsonPath('match', 'exact');
});

it('reports the corrected query when the service sends one', function () {
    Http::fake(['search.test/api/search*' => Http::response([
        'match' => 'corrected',
        'corrected' => 'chair',
        'results' => [['reference' => 'chair', 'score' => 8.1]],
    ])]);

    $this->getJson('/!/search-service/search?q=cheir')
        ->assertOk()
        ->assertJsonPath('corrected', 'chair');
});

it('reports a null corrected query when the service omits one', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/search?q=sofa')
        ->assertOk()
        ->assertJsonPath('corrected', null);
});

it('drops a reference that does not resolve to an entry', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'missing', 'score' => 9.9],
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/search?q=sofa')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.reference', 'sofa');
});

it('drops an unpublished entry', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'draft', 'score' => 9.9],
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/search?q=sofa')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.reference', 'sofa');
});

it('rejects a request with no query', function () {
    Http::fake();

    $this->getJson('/!/search-service/search')->assertJsonValidationErrors('q');

    Http::assertNothingSent();
});

it('rejects a blank query', function () {
    Http::fake();

    $this->getJson('/!/search-service/search?q=')->assertJsonValidationErrors('q');

    Http::assertNothingSent();
});

it('returns a 503 when the search service cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Could not connect'));

    $this->getJson('/!/search-service/search?q=sofa')
        ->assertStatus(503)
        ->assertExactJson(['message' => 'Search is unavailable.']);
});

it('sends the query and limit to the search service', function () {
    Http::fake(['*' => Http::response(['results' => []])]);

    $this->getJson('/!/search-service/search?q=sofa&limit=5')->assertOk();

    [$request] = Http::recorded()->first();

    expect($request->method())->toBe('GET')
        ->and($request->url())->toBe('https://search.test/api/search?q=sofa&limit=5&offset=0');
});

it('defaults the limit to 10 when none is given', function () {
    Http::fake(['*' => Http::response(['results' => []])]);

    $this->getJson('/!/search-service/search?q=sofa')->assertOk();

    [$request] = Http::recorded()->first();

    expect($request->url())->toBe('https://search.test/api/search?q=sofa&limit=10&offset=0');
});

it('asks the service for the requested offset and reports the total', function () {
    Http::fake(['search.test/api/search*' => Http::response([
        'total' => 7,
        'results' => [['reference' => 'sofa', 'score' => 12.5]],
    ])]);

    $this->getJson('/!/search-service/search?q=sofa&limit=1&offset=4')
        ->assertOk()
        ->assertJson(['limit' => 1, 'offset' => 4, 'total' => 7]);

    [$request] = Http::recorded()->first();

    expect($request->url())->toBe('https://search.test/api/search?q=sofa&limit=1&offset=4');
});

it('rejects a negative offset', function () {
    Http::fake();

    $this->getJson('/!/search-service/search?q=sofa&offset=-1')->assertUnprocessable();

    Http::assertNothingSent();
});
