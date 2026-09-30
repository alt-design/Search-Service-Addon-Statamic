<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

it('returns hydrated results with the intent the service inferred', function () {
    Http::fake(['search.test/api/ask*' => Http::response([
        'total' => 1,
        'match' => 'intent',
        'corrected' => null,
        'intent' => [
            'source' => 'vocabulary',
            'terms' => ['red', 'chair'],
            'concepts' => [
                ['facet' => 'colour', 'value' => 'red'],
                ['facet' => 'type', 'value' => 'chair'],
            ],
            'unmatched' => [
                ['facet' => 'colour', 'value' => 'red'],
            ],
        ],
        'results' => [
            ['reference' => 'chair', 'score' => 1.84],
        ],
    ])]);

    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => 'i want a red chair', 'limit' => 20]))
        ->assertOk()
        ->assertExactJson([
            'query' => 'i want a red chair',
            'limit' => 20,
            'offset' => 0,
            'total' => 1,
            'match' => 'intent',
            'corrected' => null,
            'dropped' => null,
            'intent' => [
                'source' => 'vocabulary',
                'terms' => ['red', 'chair'],
                'corrected' => null,
                'concepts' => [
                    ['facet' => 'colour', 'value' => 'red'],
                    ['facet' => 'type', 'value' => 'chair'],
                ],
                'unmatched' => [
                    ['facet' => 'colour', 'value' => 'red'],
                ],
            ],
            'results' => [
                ['reference' => 'chair', 'score' => 1.84, 'title' => 'Armchairs', 'url' => '/articles/chair', 'collection' => 'articles'],
            ],
        ]);
});

it('rejects a request with no query', function () {
    Http::fake();

    $this->getJson('/!/search-service/ask?'.http_build_query([]))->assertJsonValidationErrors('q');

    Http::assertNothingSent();
});

it('rejects a blank query', function () {
    Http::fake();

    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => '']))->assertJsonValidationErrors('q');

    Http::assertNothingSent();
});

it('returns a 503 when the search service cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Could not connect'));

    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => 'red chair']))
        ->assertStatus(503)
        ->assertExactJson(['message' => 'Search is unavailable.']);
});

it('returns a 503 and logs a warning when the site has not opted in to ask', function () {
    Log::spy();

    Http::fake(['search.test/api/ask*' => Http::response(['message' => 'Forbidden.'], 403)]);

    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => 'red chair']))
        ->assertStatus(503)
        ->assertExactJson(['message' => 'Search is unavailable.']);

    Log::shouldHaveReceived('warning')->once();
});

it('drops a reference that does not resolve to an entry', function () {
    Http::fake(['search.test/api/ask*' => Http::response(['results' => [
        ['reference' => 'missing', 'score' => 9.9],
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => 'sofa']))
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.reference', 'sofa');
});

it('drops an unpublished entry', function () {
    Http::fake(['search.test/api/ask*' => Http::response(['results' => [
        ['reference' => 'draft', 'score' => 9.9],
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => 'sofa']))
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.reference', 'sofa');
});

it('sends the query, limit and offset to the search service', function () {
    Http::fake(['*' => Http::response(['results' => []])]);

    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => 'red chair', 'limit' => 5, 'offset' => 2]))->assertOk();

    [$request] = Http::recorded()->first();

    expect($request->method())->toBe('POST')
        ->and($request['q'])->toBe('red chair')
        ->and($request['limit'])->toBe(5)
        ->and($request['offset'])->toBe(2);
});

it('caches a repeated identical ask for the configured TTL, asking the service once', function () {
    Http::fake(['search.test/api/ask*' => Http::response(['results' => [
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => 'red chair', 'limit' => 5]))->assertOk();
    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => 'red chair', 'limit' => 5]))->assertOk();

    expect(Http::recorded())->toHaveCount(1);
});

it('does not share its cache with a search for the same words', function () {
    Http::fake([
        'search.test/api/search*' => Http::response(['results' => []]),
        'search.test/api/ask*' => Http::response(['results' => []]),
    ]);

    $this->getJson('/!/search-service/search?q=chair')->assertOk();
    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => 'chair']))->assertOk();

    expect(Http::recorded())->toHaveCount(2);
});

it('reports the meaning the service had to give up', function () {
    Http::fake(['search.test/api/ask*' => Http::response([
        'total' => 1,
        'match' => 'relaxed',
        'corrected' => null,
        'dropped' => ['facet' => 'type', 'value' => 'chair'],
        'intent' => ['source' => 'vocabulary', 'terms' => ['red', 'chair'], 'concepts' => [], 'unmatched' => []],
        'results' => [['reference' => 'chair', 'score' => 1.84]],
    ])]);

    $this->getJson('/!/search-service/ask?'.http_build_query(['q' => 'a red chair']))
        ->assertOk()
        ->assertJsonPath('match', 'relaxed')
        ->assertJsonPath('dropped', ['facet' => 'type', 'value' => 'chair']);
});
