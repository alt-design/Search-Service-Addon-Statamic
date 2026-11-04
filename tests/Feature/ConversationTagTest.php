<?php

use AltDesign\SearchService\Chosen;
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
        'search-service.search_cache_seconds' => 0,
    ]);

    Queue::fake();

    Collection::make('articles')->title('Articles')->routes('/articles/{slug}')->save();
    Entry::make()->collection('articles')->id('chair')->slug('chair')->published(true)
        ->data(['title' => 'Armchairs'])->save();

    $this->template = '{{ search_service:ask q="{{ q }}" }}'
        .'{{ chosen }}[{{ facet }}={{ value }} {{ link }}]{{ /chosen }}'
        .'{{ if question }}ASK {{ question:facet }}:'
        .'{{ question:options }}({{ value }},{{ total }},{{ link }}){{ /question:options }}'
        .'{{ else }}NO QUESTION{{ /if }}'
        .'{{ /search_service:ask }}';

    $this->render = fn (array $vars = []) => (string) Antlers::parse($this->template, $vars, true);
});

function answers(array $extra = []): void
{
    Http::fake(['search.test/api/ask*' => Http::response([
        'match' => 'intent',
        'intent' => ['source' => 'vocabulary', 'terms' => [], 'concepts' => [], 'unmatched' => []],
        'results' => [['reference' => 'chair', 'score' => 8.1]],
        ...$extra,
    ])]);
}

it('renders the question with a link that adds the option to what is settled', function () {
    answers(['question' => ['facet' => 'colour', 'options' => [
        ['value' => 'red', 'total' => 6],
        ['value' => 'blue', 'total' => 4],
    ]]]);

    expect(($this->render)(['q' => 'chair']))
        ->toContain('ASK colour:')
        ->toContain('(red,6,q=chair&chosen=colour%3Ared)')
        ->toContain('(blue,4,q=chair&chosen=colour%3Ablue)');
});

it('renders nothing to ask when the service has nothing worth asking', function () {
    answers(['question' => null]);

    expect(($this->render)(['q' => 'chair']))->toContain('NO QUESTION');
});

it('renders each settled pair with a link that unsettles only that one', function () {
    answers([
        'chosen' => [['facet' => 'colour', 'value' => 'red'], ['facet' => 'material', 'value' => 'oak']],
        'question' => null,
    ]);

    expect(($this->render)(['q' => 'chair']))
        ->toContain('[colour=red q=chair&chosen=material%3Aoak]')
        ->toContain('[material=oak q=chair&chosen=colour%3Ared]');
});

it('sends what the url says is settled to the service', function () {
    answers();

    $this->get('/?q=chair&chosen='.rawurlencode('colour:red|material:oak'));

    ($this->render)(['q' => 'chair']);

    Http::assertSent(fn ($request): bool => $request['chosen'] === [
        ['facet' => 'colour', 'value' => 'red'],
        ['facet' => 'material', 'value' => 'oak'],
    ]);
});

it('does not serve one turn of a conversation from another turn cache', function () {
    config(['search-service.search_cache_seconds' => 60]);
    answers();

    AltDesign\SearchService\Search::ask('chair', 10, 0, null, Chosen::from('colour:red'));
    AltDesign\SearchService\Search::ask('chair', 10, 0, null, Chosen::from('colour:blue'));

    Http::assertSentCount(2);
});

it('reads a value holding the separators it is encoded with', function () {
    $chosen = Chosen::none()->with('type', 'two:seater|sofa');

    expect(Chosen::from($chosen->parameter())->toQuery())
        ->toBe([['facet' => 'type', 'value' => 'two:seater|sofa']]);
});

it('drops a mangled pair rather than breaking the page', function () {
    expect(Chosen::from('colour:red|nonsense|:|material:oak')->toQuery())
        ->toBe([['facet' => 'colour', 'value' => 'red'], ['facet' => 'material', 'value' => 'oak']]);
});
