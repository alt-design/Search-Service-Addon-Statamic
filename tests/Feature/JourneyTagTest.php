<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Antlers;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

uses(PreventsSavingStacheItemsToDisk::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function journeyTurn(array $overrides = []): void
{
    Http::fake(['search.test/api/journey*' => Http::response([
        'key' => 'hire-enquiry',
        'name' => 'Hire enquiry',
        'prompt' => null,
        'node' => 'wedding-hire',
        'state' => ['node' => 'wedding-hire', 'answers' => [['facet' => 'range', 'value' => 'Weddings']]],
        'question' => ['facet' => 'colour', 'options' => [['value' => 'gold', 'total' => 4]]],
        'handoff' => null,
        'total' => 1,
        'facets' => ['colour' => [['value' => 'gold', 'total' => 4]]],
        'results' => [['reference' => 'sofa', 'score' => 1.0]],
        ...$overrides,
    ])]);
}

beforeEach(function () {
    config(['search-service.url' => 'https://search.test', 'search-service.key' => '1|secret']);

    Queue::fake();

    Collection::make('articles')->title('Articles')->routes('/articles/{slug}')->save();
    Entry::make()->collection('articles')->id('sofa')->slug('sofa')->published(true)
        ->data(['title' => 'Sofa Beds'])->save();
});

it('opens a journey with its greeting when nothing has been said', function () {
    journeyTurn(['prompt' => 'How can I help you today?', 'node' => null, 'question' => null, 'total' => 0, 'results' => []]);

    $out = Antlers::parse('{{ search_service:journey key="hire-enquiry" }}{{ prompt }}{{ /search_service:journey }}', [], true);

    expect(trim($out))->toBe('How can I help you today?');
});

it('says nothing useful without a key rather than calling the service', function () {
    Http::fake();

    $out = Antlers::parse('{{ search_service:journey }}{{ if failed }}no key{{ /if }}{{ /search_service:journey }}', [], true);

    expect(trim($out))->toBe('no key');

    Http::assertNothingSent();
});

it('hands the state back ready for a hidden input', function () {
    journeyTurn();

    $out = Antlers::parse('{{ search_service:journey key="hire-enquiry" }}{{ state }}{{ /search_service:journey }}', [], true);

    expect(trim($out))->toBe('wedding-hire!range:Weddings');
});

it('hydrates the results the service named', function () {
    journeyTurn();

    $out = Antlers::parse(
        '{{ search_service:journey key="hire-enquiry" }}{{ results }}{{ title }}{{ /results }}{{ /search_service:journey }}',
        [], true,
    );

    expect(trim($out))->toBe('Sofa Beds');
});

it('offers each option as a link that answers the question', function () {
    journeyTurn();

    $out = Antlers::parse(
        '{{ search_service:journey key="hire-enquiry" }}{{ question:options }}{{ value }} ({{ total }}) {{ link }}{{ /question:options }}{{ /search_service:journey }}',
        [], true,
    );

    expect(trim($out))->toBe('gold (4) message=gold&state=wedding-hire%21range%3AWeddings');
});

it('offers what is settled with the link that unsettles it', function () {
    journeyTurn();

    $out = Antlers::parse(
        '{{ search_service:journey key="hire-enquiry" }}{{ settled }}{{ facet }}:{{ value }} {{ link }}{{ /settled }}{{ /search_service:journey }}',
        [], true,
    );

    expect(trim($out))->toBe('range:Weddings state=wedding-hire');
});

it('passes the handoff through when the visitor is sent elsewhere', function () {
    journeyTurn([
        'handoff' => ['url' => '/contact', 'message' => 'We cannot help with that here.'],
        'question' => null,
        'total' => 0,
        'results' => [],
    ]);

    $out = Antlers::parse(
        '{{ search_service:journey key="hire-enquiry" }}{{ handoff }}{{ message }} {{ url }}{{ /handoff }}{{ /search_service:journey }}',
        [], true,
    );

    expect(trim($out))->toBe('We cannot help with that here. /contact');
});

it('reports a failure rather than pretending the visitor was sent away', function () {
    Http::fake(['search.test/api/journey*' => Http::response([], 503)]);

    $out = Antlers::parse(
        '{{ search_service:journey key="hire-enquiry" }}{{ if failed }}busy{{ else }}fine{{ /if }}{{ /search_service:journey }}',
        [], true,
    );

    expect(trim($out))->toBe('busy');
});

it('takes the message from the request so the page is a form posting to itself', function () {
    journeyTurn();

    request()->merge(['message' => 'wedding tables', 'state' => 'wedding-hire']);

    Antlers::parse('{{ search_service:journey key="hire-enquiry" }}{{ /search_service:journey }}', [], true);

    Http::assertSent(fn ($request): bool => $request->data()['message'] === 'wedding tables');
});

it('escapes a facet value so an indexed script cannot reach the page', function () {
    journeyTurn([
        'question' => ['facet' => 'colour', 'options' => [['value' => '<script>alert(1)</script>', 'total' => 2]]],
    ]);

    $out = Antlers::parse(
        '{{ search_service:journey key="hire-enquiry" }}{{ question:options }}{{ value }}{{ /question:options }}{{ /search_service:journey }}',
        [], true,
    );

    expect((string) $out)->not->toContain('<script>')
        ->and((string) $out)->toContain('&lt;script&gt;');
});

it('escapes the journey name and the handoff message', function () {
    journeyTurn([
        'name' => '<script>alert(1)</script>',
        'handoff' => ['url' => '/contact', 'message' => '<img onerror=alert(1)>'],
    ]);

    $out = Antlers::parse(
        '{{ search_service:journey key="hire-enquiry" }}{{ name }}{{ handoff:message }}{{ /search_service:journey }}',
        [], true,
    );

    expect((string) $out)->not->toContain('<script>')
        ->and((string) $out)->not->toContain('<img');
});
