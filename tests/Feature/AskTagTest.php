<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Antlers;
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

    $this->template = '{{ search_service:ask q="{{ q }}" limit="5" }}'
        .'{{ if no_results }}NO RESULTS{{ else }}'
        .'{{ results }}{{ title }}:{{ score }};{{ /results }}'
        .'match={{ match }} source={{ intent:source }} '
        .'{{ intent:concepts }}{{ facet }}={{ value }},{{ /intent:concepts }} '
        .'{{ intent:unmatched }}{{ facet }}={{ value }},{{ /intent:unmatched }}'
        .'{{ /if }}'
        .'{{ /search_service:ask }}';

    // Templates written into a site's views are trusted, unlike a value pulled from an
    // editable field, so real rendering always parses with $trusted true.
    $this->render = fn (array $vars = []) => (string) Antlers::parse($this->template, $vars, true);
});

it('renders hydrated results in the order the service gave them', function () {
    Http::fake(['search.test/api/ask*' => Http::response([
        'match' => 'intent',
        'intent' => ['source' => 'vocabulary', 'terms' => [], 'concepts' => [], 'unmatched' => []],
        'results' => [
            ['reference' => 'chair', 'score' => 8.1],
            ['reference' => 'sofa', 'score' => 12.5],
        ],
    ])]);

    expect(($this->render)(['q' => 'a red chair']))->toContain('Armchairs:8.1;Sofa Beds:12.5;');
});

it('renders the match mode and the concepts the service understood', function () {
    Http::fake(['search.test/api/ask*' => Http::response([
        'match' => 'intent',
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
        'results' => [['reference' => 'chair', 'score' => 8.1]],
    ])]);

    expect(($this->render)(['q' => 'i want a red chair']))
        ->toContain('match=intent source=vocabulary')
        ->toContain('colour=red,type=chair,')
        ->toContain('colour=red,');
});

it('makes no request and yields no results for a blank query', function () {
    Http::fake();

    expect(($this->render)(['q' => '']))->toBe('NO RESULTS');

    Http::assertNothingSent();
});

it('makes no request and yields no results for a missing query', function () {
    Http::fake();

    expect(($this->render)())->toBe('NO RESULTS');

    Http::assertNothingSent();
});

it('yields no results when the search service cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Could not connect'));

    expect(($this->render)(['q' => 'red chair']))->toBe('NO RESULTS');
});

it('yields no results when the site has not opted in to ask', function () {
    Http::fake(['search.test/api/ask*' => Http::response(['message' => 'Forbidden.'], 403)]);

    expect(($this->render)(['q' => 'red chair']))->toBe('NO RESULTS');
});

it('sends the query and limit to the search service', function () {
    Http::fake(['*' => Http::response(['results' => []])]);

    ($this->render)(['q' => 'red chair']);

    [$request] = Http::recorded()->first();

    expect($request->method())->toBe('POST')
        ->and($request['q'])->toBe('red chair')
        ->and($request['limit'])->toBe(5)
        ->and($request['offset'])->toBe(0);
});

it('exposes match, corrected and intent to a paginated template', function () {
    LengthAwarePaginator::currentPageResolver(fn () => 1);

    Http::fake(['search.test/api/ask*' => Http::response([
        'total' => 1,
        'match' => 'intent',
        'corrected' => null,
        'intent' => [
            'source' => 'llm',
            'terms' => ['chair'],
            'concepts' => [['facet' => 'type', 'value' => 'chair']],
            'unmatched' => [],
        ],
        'results' => [['reference' => 'chair', 'score' => 8.1]],
    ])]);

    $template = '{{ search_service:ask q="{{ q }}" paginate="2" }}'
        .'{{ results }}{{ title }};{{ /results }}'
        .'match={{ match }} source={{ intent:source }} total={{ total_results }}'
        .'{{ /search_service:ask }}';

    expect((string) Antlers::parse($template, ['q' => 'a chair'], true))
        ->toBe('Armchairs;match=intent source=llm total=1');
});
