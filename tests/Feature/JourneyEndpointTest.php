<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

uses(PreventsSavingStacheItemsToDisk::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function serviceTurn(array $overrides = []): void
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
        'facets' => [],
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

it('answers a turn with the state encoded for the next one', function () {
    serviceTurn();

    $response = $this->getJson('/!/search-service/journey?key=hire-enquiry&message=wedding+tables');

    $response->assertOk()->assertJson([
        'key' => 'hire-enquiry',
        'name' => 'Hire enquiry',
        'node' => 'wedding-hire',
        'state' => 'wedding-hire!range:Weddings',
        'total' => 1,
    ]);
});

it('hydrates each result into something a page can render', function () {
    serviceTurn();

    $response = $this->getJson('/!/search-service/journey?key=hire-enquiry&message=wedding+tables');

    expect($response->json('results.0'))->toMatchArray([
        'reference' => 'sofa',
        'title' => 'Sofa Beds',
        'collection' => 'articles',
    ]);
});

it('sends the state it was given back to the service', function () {
    serviceTurn();

    $this->getJson('/!/search-service/journey?key=hire-enquiry&message=the+gold+ones&state=wedding-hire%21range%3AWeddings');

    Http::assertSent(fn ($request): bool => $request->data()['state'] === [
        'node' => 'wedding-hire',
        'answers' => [['facet' => 'range', 'value' => 'Weddings']],
        'wants' => [],
    ]);
});

it('opens the journey when no message is sent', function () {
    serviceTurn(['prompt' => 'How can I help you today?', 'node' => null, 'question' => null, 'total' => 0, 'results' => []]);

    $this->getJson('/!/search-service/journey?key=hire-enquiry')
        ->assertOk()
        ->assertJson(['prompt' => 'How can I help you today?']);
});

it('passes a handoff through', function () {
    serviceTurn(['handoff' => ['url' => '/contact', 'message' => 'Not here.'], 'question' => null, 'results' => []]);

    $this->getJson('/!/search-service/journey?key=hire-enquiry&message=where+is+my+order')
        ->assertOk()
        ->assertJson(['handoff' => ['url' => '/contact', 'message' => 'Not here.']]);
});

it('hands over the fields a page asked for alongside each result', function () {
    serviceTurn();
    Entry::find('sofa')->set('price', 14.6)->set('sku', 'CC-09')->save();

    $response = $this->getJson('/!/search-service/journey?key=hire-enquiry&message=anything&fields=price,sku');

    expect($response->json('results.0.values'))->toBe(['price' => 14.6, 'sku' => 'CC-09']);
});

it('hands over nothing extra when nothing was asked for', function () {
    serviceTurn();

    $response = $this->getJson('/!/search-service/journey?key=hire-enquiry&message=anything');

    expect($response->json('results.0.values'))->toBe([]);
});

it('caps how many fields one request can ask for', function () {
    serviceTurn();

    $many = implode(',', array_map(fn (int $i): string => "field{$i}", range(1, 20)));

    $this->getJson("/!/search-service/journey?key=hire-enquiry&message=anything&fields={$many}")
        ->assertOk();
});

it('says the journey is unavailable rather than inventing a handoff', function () {
    Http::fake(['search.test/api/journey*' => Http::response([], 503)]);

    $this->getJson('/!/search-service/journey?key=hire-enquiry&message=anything')
        ->assertStatus(503)
        ->assertJson(['message' => 'That journey is unavailable.']);
});

it('says so when the service has no journey by that key', function () {
    Http::fake(['search.test/api/journey*' => Http::response([], 404)]);

    $this->getJson('/!/search-service/journey?key=nope&message=anything')->assertStatus(503);
});

it('takes a key', function () {
    Http::fake();

    $this->getJson('/!/search-service/journey')->assertStatus(422);

    Http::assertNothingSent();
});

it('refuses a message longer than anybody types', function () {
    Http::fake();

    $this->getJson('/!/search-service/journey?key=hire-enquiry&message='.str_repeat('a', 501))
        ->assertStatus(422);
});
