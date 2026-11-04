<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Antlers;
use Statamic\Facades\Collection;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

uses(PreventsSavingStacheItemsToDisk::class);

beforeEach(function () {
    config([
        'search-service.url' => 'https://search.test',
        'search-service.key' => '1|secret',
        'search-service.search_cache_seconds' => 0,
    ]);

    Queue::fake();
    Collection::make('articles')->title('Articles')->routes('/articles/{slug}')->save();
});

function serviceSends(array $payload): void
{
    Http::fake(['search.test/api/ask*' => Http::response([
        'match' => 'intent',
        'intent' => ['source' => 'vocabulary', 'terms' => [], 'concepts' => [], 'unmatched' => []],
        'results' => [],
        ...$payload,
    ])]);
}

test('a hostile value in the question cannot become markup', function () {
    serviceSends([
        'chosen' => [['facet' => 'colour', 'value' => '"><script>alert(1)</script>']],
        'question' => ['facet' => '<img src=x onerror=alert(2)>', 'options' => [
            ['value' => '"><script>alert(3)</script>', 'total' => 2],
        ]],
    ]);

    $html = (string) Antlers::parse(
        '{{ search_service:ask q="chair" }}'
        .'{{ chosen }}<a href="?{{ link }}">{{ facet }}: {{ value }}</a>{{ /chosen }}'
        .'<p>{{ question:facet }}</p>'
        .'{{ question:options }}<a href="?{{ link }}">{{ value }}</a>{{ /question:options }}'
        .'{{ /search_service:ask }}',
        [],
        true,
    );

    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('"><')
        ->and($html)->toContain('&lt;script&gt;');
});

test('a hostile value in what the query was understood to mean cannot become markup', function () {
    serviceSends([
        'corrected' => '<script>alert(1)</script>',
        'dropped' => ['facet' => '<script>alert(2)</script>', 'value' => 'red'],
        'intent' => [
            'source' => 'vocabulary',
            'terms' => ['<script>alert(3)</script>'],
            'corrected' => null,
            'concepts' => [['facet' => 'colour', 'value' => '<script>alert(4)</script>']],
            'unmatched' => [],
        ],
    ]);

    $html = (string) Antlers::parse(
        '{{ search_service:ask q="chair" }}'
        .'{{ corrected }}{{ dropped:facet }}'
        .'{{ intent:terms }}{{ value }}{{ /intent:terms }}'
        .'{{ intent:concepts }}{{ facet }}{{ value }}{{ /intent:concepts }}'
        .'{{ /search_service:ask }}',
        [],
        true,
    );

    expect($html)->not->toContain('<script')
        ->and($html)->toContain('&lt;script&gt;');
});

test('a hostile facet value on the results tag cannot become markup', function () {
    Http::fake(['search.test/api/search*' => Http::response([
        'match' => 'exact',
        'corrected' => null,
        'facets' => ['tags' => [['value' => '"><script>alert(1)</script>', 'total' => 2]]],
        'results' => [],
    ])]);

    $html = (string) Antlers::parse(
        '{{ search_service:results q="chair" }}{{ facets:tags }}<a title="{{ value }}">{{ value }}</a>{{ /facets:tags }}{{ /search_service:results }}',
        [],
        true,
    );

    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('"><');
});

test('escaping does not corrupt the link, which carries the real value', function () {
    serviceSends(['question' => ['facet' => 'colour', 'options' => [['value' => 'black & white', 'total' => 2]]]]);

    $html = (string) Antlers::parse(
        '{{ search_service:ask q="chair" }}{{ question:options }}{{ link }}|{{ value }}{{ /question:options }}{{ /search_service:ask }}',
        [],
        true,
    );

    expect($html)->toContain('black%2520%2526%2520white')
        ->and($html)->toContain('black &amp; white');
});
