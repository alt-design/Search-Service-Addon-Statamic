<?php

use AltDesign\SearchService\Jobs\SyncCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Collection;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

uses(FakesRoles::class, PreventsSavingStacheItemsToDisk::class);

/**
 * The fields grid inside a collection panel, found by handle so adding another field to
 * the panel does not move it.
 */
function gridFor(array $set): array
{
    return collect($set['fields'])->firstWhere('handle', 'fields');
}

beforeEach(function () {
    config([
        'search-service.url' => 'https://search.test',
        'search-service.key' => '1|secret',
    ]);

    Collection::make('articles')->title('Articles')->save();
    Collection::make('pages')->title('Pages')->save();

    Queue::fake();

    $this->actingAs(User::make()->id('admin')->email('admin@alt.test')->makeSuper());
});

it('groups the service field configs into a panel per collection', function () {
    Http::fake(['search.test/api/fields' => Http::response(['fields' => [
        ['field' => 'articles.body', 'weight' => 1],
        ['field' => 'articles.title', 'weight' => 20],
        ['field' => 'pages.title', 'weight' => 10],
    ]])]);

    $this->getJson(cp_route('search-service.fields.edit'))
        ->assertOk()
        ->assertJsonPath('values.collections.0.type', 'articles')
        ->assertJsonPath('values.collections.0.fields.0.field', 'body')
        ->assertJsonPath('values.collections.0.fields.0.weight', 1)
        ->assertJsonPath('values.collections.0.fields.1.field', 'title')
        ->assertJsonPath('values.collections.0.fields.1.weight', 20)
        ->assertJsonPath('values.collections.1.type', 'pages')
        ->assertJsonPath('values.collections.1.fields.0.field', 'title');
});

it('puts a config whose collection is gone into its own panel, keeping the whole name', function () {
    Http::fake(['search.test/api/fields' => Http::response(['fields' => [
        ['field' => 'articles.title', 'weight' => 20],
        ['field' => 'products.title', 'weight' => 5],
        ['field' => 'title', 'weight' => 1],
    ]])]);

    $this->getJson(cp_route('search-service.fields.edit'))
        ->assertOk()
        ->assertJsonPath('values.collections.0.type', 'articles')
        ->assertJsonPath('values.collections.1.type', '_orphaned')
        ->assertJsonPath('values.collections.1.fields.0.field', 'products.title')
        ->assertJsonPath('values.collections.1.fields.1.field', 'title');
});

it('offers a collection its own blueprint fields, weighted with a slider', function () {
    Http::fake(['search.test/api/fields' => Http::response(['fields' => []])]);

    $response = $this->getJson(cp_route('search-service.fields.edit'))->assertOk();

    $sets = collect($response->json('blueprint.tabs.0.sections.0.fields.0.sets'))
        ->flatMap(fn ($group) => $group['sets'] ?? [])
        ->keyBy('handle');

    $columns = collect(gridFor($sets['articles'])['fields'])->keyBy('handle');

    expect($sets->keys())->toContain('articles', 'pages', '_orphaned')
        ->and($sets['articles']['display'])->toBe('Articles')
        ->and(array_keys($columns['field']['options']))->toContain('title', 'content')
        ->and($columns['weight']['type'])->toBe('range')
        ->and($columns['weight']['max'])->toBe(100);
});

it('saves field names prefixed with their collection', function () {
    Http::fake(['*' => Http::response(['fields' => [], 'removed' => []])]);

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['_id' => 'a', 'type' => 'articles', 'enabled' => true, 'fields' => [
            ['_id' => 'r1', 'field' => 'title', 'weight' => '20'],
            ['_id' => 'r2', 'field' => 'slug', 'weight' => '1'],
        ]],
        ['_id' => 'b', 'type' => 'pages', 'enabled' => true, 'fields' => [
            ['_id' => 'r3', 'field' => 'title', 'weight' => '10'],
        ]],
    ]])->assertOk()->assertJson(['saved' => true]);

    [$request] = Http::recorded()->first();

    expect($request->method())->toBe('PUT')
        ->and($request->url())->toBe('https://search.test/api/fields')
        ->and($request->data())->toBe(['fields' => [
            ['field' => 'articles.title', 'weight' => 20, 'enabled' => true],
            ['field' => 'articles.slug', 'weight' => 1, 'enabled' => true],
            ['field' => 'pages.title', 'weight' => 10, 'enabled' => true],
        ]]);
});

it('sends an orphaned field under the name it already has', function () {
    Http::fake(['*' => Http::response(['fields' => [], 'removed' => []])]);

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['_id' => 'a', 'type' => '_orphaned', 'enabled' => true, 'fields' => [
            ['_id' => 'r1', 'field' => 'products.title', 'weight' => '5'],
        ]],
    ]])->assertOk();

    [$request] = Http::recorded()->first();

    expect($request->data())->toBe(['fields' => [['field' => 'products.title', 'weight' => 5, 'enabled' => true]]]);
});

it('sends every field in a disabled panel as disabled instead of dropping them', function () {
    Http::fake(['*' => Http::response(['fields' => [], 'removed' => []])]);

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['_id' => 'a', 'type' => 'articles', 'enabled' => false, 'fields' => [
            ['_id' => 'r1', 'field' => 'title', 'weight' => '20', 'enabled' => true],
            ['_id' => 'r2', 'field' => 'slug', 'weight' => '5', 'enabled' => false],
        ]],
        ['_id' => 'b', 'type' => 'pages', 'enabled' => true, 'fields' => [
            ['_id' => 'r3', 'field' => 'title', 'weight' => '10'],
        ]],
    ]])->assertOk();

    [$request] = Http::recorded()->first();

    expect($request->data())->toBe(['fields' => [
        ['field' => 'articles.title', 'weight' => 20, 'enabled' => false],
        ['field' => 'articles.slug', 'weight' => 5, 'enabled' => false],
        ['field' => 'pages.title', 'weight' => 10, 'enabled' => true],
    ]]);
});

it('sends a disabled row as disabled while its panel stays enabled', function () {
    Http::fake(['*' => Http::response(['fields' => [], 'removed' => []])]);

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['_id' => 'a', 'type' => 'articles', 'enabled' => true, 'fields' => [
            ['_id' => 'r1', 'field' => 'title', 'weight' => '20', 'enabled' => false],
            ['_id' => 'r2', 'field' => 'slug', 'weight' => '1', 'enabled' => true],
        ]],
    ]])->assertOk();

    [$request] = Http::recorded()->first();

    expect($request->data())->toBe(['fields' => [
        ['field' => 'articles.title', 'weight' => 20, 'enabled' => false],
        ['field' => 'articles.slug', 'weight' => 1, 'enabled' => true],
    ]]);
});

it('maps a field enabled state from the service into its row', function () {
    Http::fake(['search.test/api/fields' => Http::response(['fields' => [
        ['field' => 'articles.title', 'weight' => 20, 'enabled' => true],
        ['field' => 'articles.body', 'weight' => 1, 'enabled' => false],
    ]])]);

    $this->getJson(cp_route('search-service.fields.edit'))
        ->assertOk()
        ->assertJsonPath('values.collections.0.fields.0.enabled', true)
        ->assertJsonPath('values.collections.0.fields.1.enabled', false);
});

it('defaults a row to enabled when the service omits the flag', function () {
    Http::fake(['search.test/api/fields' => Http::response(['fields' => [
        ['field' => 'articles.title', 'weight' => 20],
    ]])]);

    $this->getJson(cp_route('search-service.fields.edit'))
        ->assertOk()
        ->assertJsonPath('values.collections.0.fields.0.enabled', true);
});

it('round-trips a field enabled state through toValues and toFields', function () {
    Http::fake(['search.test/api/fields' => Http::response(['fields' => [
        ['field' => 'articles.title', 'weight' => 20, 'enabled' => true],
        ['field' => 'articles.body', 'weight' => 1, 'enabled' => false],
    ]])]);

    $values = $this->getJson(cp_route('search-service.fields.edit'))->assertOk()->json('values');

    Http::fake(['*' => Http::response(['fields' => [], 'removed' => []])]);

    $this->patchJson(cp_route('search-service.fields.update'), $values)->assertOk();

    [$request] = Http::recorded()->first();

    expect($request->data())->toBe(['fields' => [
        ['field' => 'articles.title', 'weight' => 20, 'enabled' => true],
        ['field' => 'articles.body', 'weight' => 1, 'enabled' => false],
    ]]);
});

it('shows the search service error when it rejects the fields', function () {
    Http::fake(['*' => Http::response(['message' => 'The fields.1.field field has a duplicate value.'], 422)]);

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['_id' => 'a', 'type' => 'articles', 'enabled' => true, 'fields' => [
            ['_id' => 'r1', 'field' => 'title', 'weight' => '20'],
        ]],
    ]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['collections' => 'The fields.1.field field has a duplicate value.']);
});

it('sends the user back to the status page when the service cannot be reached', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $this->get(cp_route('search-service.fields.edit'))
        ->assertRedirect(cp_route('search-service.index'))
        ->assertSessionHas('error');
});

it('only lets users with the edit permission save', function () {
    Http::fake();
    $this->setTestRoles(['viewer' => ['access cp', 'view search-service']]);

    $this->actingAs(User::make()->id('viewer')->email('viewer@alt.test')->assignRole('viewer'))
        ->patchJson(cp_route('search-service.fields.update'), ['collections' => []])
        ->assertForbidden();

    Http::assertNothingSent();
});

it('queues an index of every collection it saved', function () {
    Http::fake(['*' => Http::response(['fields' => [], 'removed' => []])]);

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['_id' => 'a', 'type' => 'articles', 'enabled' => true, 'fields' => [
            ['_id' => 'r1', 'field' => 'title', 'weight' => '20'],
            ['_id' => 'r2', 'field' => 'slug', 'weight' => '1'],
        ]],
        ['_id' => 'b', 'type' => 'pages', 'enabled' => true, 'fields' => [
            ['_id' => 'r3', 'field' => 'title', 'weight' => '10'],
        ]],
    ]])->assertOk();

    Queue::assertPushed(SyncCollection::class, 2);
    Queue::assertPushed(SyncCollection::class, fn (SyncCollection $job) => $job->collection === 'articles');
    Queue::assertPushed(SyncCollection::class, fn (SyncCollection $job) => $job->collection === 'pages');
});

it('queues no index when the service rejects the save', function () {
    Http::fake(['*' => Http::response(['message' => 'Nope.'], 422)]);

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['_id' => 'a', 'type' => 'articles', 'enabled' => true, 'fields' => [
            ['_id' => 'r1', 'field' => 'title', 'weight' => '20'],
        ]],
    ]])->assertUnprocessable();

    Queue::assertNotPushed(SyncCollection::class);
});

it('prefills a new collection panel with the fields worth indexing', function () {
    Http::fake(['search.test/api/fields' => Http::response(['fields' => []])]);

    $response = $this->getJson(cp_route('search-service.fields.edit'))->assertOk();

    $sets = collect($response->json('blueprint.tabs.0.sections.0.fields.0.sets'))
        ->flatMap(fn ($group) => $group['sets'] ?? [])
        ->keyBy('handle');

    expect(gridFor($sets['articles'])['default'])->toBe([
        ['field' => 'title', 'weight' => 50, 'enabled' => true],
        ['field' => 'content', 'weight' => 1, 'enabled' => true],
    ])->and(gridFor($sets['_orphaned'])['default'])->toBe([]);
});

it('loads what each collection told the service it is', function () {
    Http::fake([
        'search.test/api/fields' => Http::response(['fields' => [['field' => 'articles.title', 'weight' => 20]]]),
        'search.test/api/site' => Http::response(['context' => ['articles' => 'Buying guides about furniture.']]),
    ]);

    $this->getJson(cp_route('search-service.fields.edit'))
        ->assertOk()
        ->assertJsonPath('values.collections.0.type', 'articles')
        ->assertJsonPath('values.collections.0.context', 'Buying guides about furniture.');
});

it('sends what each collection is to the service when the fields are saved', function () {
    Http::fake(['search.test/api/*' => Http::response(['fields' => []])]);

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['type' => 'articles', 'enabled' => true, 'context' => '  Buying guides about furniture.  ', 'fields' => [
            ['field' => 'title', 'weight' => 20, 'enabled' => true],
        ]],
        ['type' => 'pages', 'enabled' => true, 'context' => '', 'fields' => [
            ['field' => 'title', 'weight' => 10, 'enabled' => true],
        ]],
    ]])->assertOk();

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/site')
        && $request->data() === ['context' => ['articles' => 'Buying guides about furniture.']]);
});

it('still saves the fields when the service will not take the descriptions', function () {
    Http::fake([
        'search.test/api/site' => Http::response(['message' => 'nope'], 422),
        'search.test/api/fields' => Http::response(['fields' => []]),
    ]);

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['type' => 'articles', 'enabled' => true, 'context' => 'Buying guides.', 'fields' => [
            ['field' => 'title', 'weight' => 20, 'enabled' => true],
        ]],
    ]])->assertOk()->assertJsonPath('saved', true);
});

it('keeps the editor working when the service cannot say what it holds', function () {
    Http::fake([
        'search.test/api/fields' => Http::response(['fields' => [['field' => 'articles.title', 'weight' => 20]]]),
        'search.test/api/site' => Http::response('', 500),
    ]);

    $this->getJson(cp_route('search-service.fields.edit'))
        ->assertOk()
        ->assertJsonPath('values.collections.0.context', null);
});
