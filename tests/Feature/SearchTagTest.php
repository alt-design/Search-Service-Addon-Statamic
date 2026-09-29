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
    Entry::make()->collection('articles')->id('draft')->slug('draft')->published(false)
        ->data(['title' => 'Draft Sofa'])->save();

    $this->template = '{{ search_service:results q="{{ q }}" limit="5" }}'
        .'{{ if no_results }}NO RESULTS{{ else }}{{ results }}{{ title }}:{{ score }};{{ /results }}{{ /if }}'
        .'{{ /search_service:results }}';

    // Templates written into a site's views are trusted, unlike a value pulled from an
    // editable field, so real rendering always parses with $trusted true.
    $this->render = fn (array $vars = []) => (string) Antlers::parse($this->template, $vars, true);
});

it('renders hydrated results in the order the service gave them', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'chair', 'score' => 8.1],
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    expect(($this->render)(['q' => 'sofa']))->toBe('Armchairs:8.1;Sofa Beds:12.5;');
});

it('drops a reference that does not resolve to an entry', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'missing', 'score' => 9.9],
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    expect(($this->render)(['q' => 'sofa']))->toBe('Sofa Beds:12.5;');
});

it('drops an unpublished entry', function () {
    Http::fake(['search.test/api/search*' => Http::response(['results' => [
        ['reference' => 'draft', 'score' => 9.9],
        ['reference' => 'sofa', 'score' => 12.5],
    ]])]);

    expect(($this->render)(['q' => 'sofa']))->toBe('Sofa Beds:12.5;');
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

    expect(($this->render)(['q' => 'sofa']))->toBe('NO RESULTS');
});

it('sends the query and limit to the search service', function () {
    Http::fake(['*' => Http::response(['results' => []])]);

    ($this->render)(['q' => 'sofa']);

    [$request] = Http::recorded()->first();

    expect($request->method())->toBe('GET')
        ->and($request->url())->toBe('https://search.test/api/search?q=sofa&limit=5&offset=0');
});

it('pages results with the paginate parameter', function () {
    LengthAwarePaginator::currentPageResolver(fn () => 2);
    Http::fake(['search.test/api/search*' => Http::response([
        'total' => 5,
        'results' => [['reference' => 'sofa', 'score' => 12.5]],
    ])]);

    $template = '{{ search_service:results q="{{ q }}" paginate="2" }}'
        .'{{ results }}{{ title }};{{ /results }}'
        .'page {{ paginate:current_page }} of {{ paginate:total_pages }}, {{ total_results }} shown'
        .'{{ /search_service:results }}';

    $output = (string) Antlers::parse($template, ['q' => 'sofa'], true);

    [$request] = Http::recorded()->first();

    expect($request->url())->toBe('https://search.test/api/search?q=sofa&limit=2&offset=2')
        ->and($output)->toContain('Sofa Beds;')
        ->and($output)->toContain('page 2 of 3, 1 shown');
});

it('yields no results when a paged query cannot reach the service', function () {
    Http::fake(['*' => Http::response([], 503)]);

    $template = '{{ search_service:results q="{{ q }}" paginate="2" }}'
        .'{{ if no_results }}NO RESULTS{{ /if }}'
        .'{{ /search_service:results }}';

    expect((string) Antlers::parse($template, ['q' => 'sofa'], true))->toContain('NO RESULTS');
});

it('exposes the match mode to a plain template', function () {
    Http::fake(['search.test/api/search*' => Http::response([
        'match' => 'corrected',
        'corrected' => 'sofa',
        'results' => [['reference' => 'sofa', 'score' => 0.8]],
    ])]);

    $template = '{{ search_service:results q="{{ q }}" limit="5" }}'
        .'{{ if match == "corrected" }}CORRECTED{{ /if }}'
        .'{{ if match == "partial" }}PARTIAL{{ /if }}'
        .'{{ /search_service:results }}';

    expect((string) Antlers::parse($template, ['q' => 'sofra'], true))->toBe('CORRECTED');
});

it('exposes the match mode to a paginated template', function () {
    Http::fake(['search.test/api/search*' => Http::response([
        'total' => 1,
        'match' => 'partial',
        'results' => [['reference' => 'sofa', 'score' => 0.8]],
    ])]);

    $template = '{{ search_service:results q="{{ q }}" paginate="2" }}'
        .'{{ if match == "corrected" }}CORRECTED{{ /if }}'
        .'{{ if match == "partial" }}PARTIAL{{ /if }}'
        .'{{ /search_service:results }}';

    expect((string) Antlers::parse($template, ['q' => 'sofa chair'], true))->toBe('PARTIAL');
});

it('defaults the match mode to exact in a template when the service omits it', function () {
    Http::fake(['search.test/api/search*' => Http::response([
        'results' => [['reference' => 'sofa', 'score' => 12.5]],
    ])]);

    $template = '{{ search_service:results q="{{ q }}" limit="5" }}{{ match }}{{ /search_service:results }}';

    expect((string) Antlers::parse($template, ['q' => 'sofa'], true))->toBe('exact');
});

it('exposes the corrected query to a plain template', function () {
    Http::fake(['search.test/api/search*' => Http::response([
        'match' => 'corrected',
        'corrected' => 'chair',
        'results' => [['reference' => 'chair', 'score' => 0.8]],
    ])]);

    $template = '{{ search_service:results q="{{ q }}" limit="5" }}'
        .'{{ if match == "corrected" }}Showing results for "{{ corrected }}".{{ /if }}'
        .'{{ /search_service:results }}';

    expect((string) Antlers::parse($template, ['q' => 'cheir'], true))
        ->toBe('Showing results for "chair".');
});

it('exposes the corrected query to a paginated template', function () {
    Http::fake(['search.test/api/search*' => Http::response([
        'total' => 1,
        'match' => 'corrected',
        'corrected' => 'chair',
        'results' => [['reference' => 'chair', 'score' => 0.8]],
    ])]);

    $template = '{{ search_service:results q="{{ q }}" paginate="2" }}'
        .'{{ if match == "corrected" }}Showing results for "{{ corrected }}".{{ /if }}'
        .'{{ /search_service:results }}';

    expect((string) Antlers::parse($template, ['q' => 'cheir'], true))
        ->toBe('Showing results for "chair".');
});
