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

it('throttles a visitor once they exceed the search rate limit, per visitor rather than globally', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => []])]);

    $limit = config('search-service.search_rate_limit');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

    for ($i = 0; $i < $limit; $i++) {
        $this->getJson('/!/search-service/search?q=sofa')->assertOk();
    }

    $this->getJson('/!/search-service/search?q=sofa')->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);

    $this->getJson('/!/search-service/search?q=sofa')->assertOk();
});

it('caches a repeated identical query for the configured TTL, asking the service once', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/search?q=sofa&limit=5')->assertOk();
    $this->getJson('/!/search-service/search?q=sofa&limit=5')->assertOk();

    expect(Http::recorded())->toHaveCount(1);
});

it('asks the service again for a different query', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/search?q=sofa&limit=5')->assertOk();
    $this->getJson('/!/search-service/search?q=chair&limit=5')->assertOk();

    expect(Http::recorded())->toHaveCount(2);
});

it('does not cache a failed response, so the next request tries the service again', function () {
    Http::fake(['search.test/api/search*' => Http::response([], 503)]);

    $this->getJson('/!/search-service/search?q=sofa')->assertStatus(503);
    $this->getJson('/!/search-service/search?q=sofa')->assertStatus(503);

    expect(Http::recorded())->toHaveCount(2);
});

it('resolves entries fresh on a cache hit, dropping one unpublished since it was cached', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'sofa', 'score' => 12.5],
        ['reference' => 'chair', 'score' => 8.1],
    ]])]);

    $this->getJson('/!/search-service/search?q=sofa')
        ->assertOk()
        ->assertJsonCount(2, 'results');

    Entry::find('chair')->published(false)->save();

    $this->getJson('/!/search-service/search?q=sofa')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.reference', 'sofa');

    expect(Http::recorded())->toHaveCount(1);
});

it('disables caching when the TTL is set to zero', function () {
    config(['search-service.search_cache_seconds' => 0]);

    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/search?q=sofa')->assertOk();
    $this->getJson('/!/search-service/search?q=sofa')->assertOk();

    expect(Http::recorded())->toHaveCount(2);
});
