<?php

use AltDesign\SearchService\Jobs\SyncCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Collection;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

uses(FakesRoles::class, PreventsSavingStacheItemsToDisk::class);

beforeEach(function () {
    config([
        'search-service.url' => 'https://search.test',
        'search-service.key' => '1|secret',
    ]);

    Collection::make('articles')->title('Articles')->save();
    Collection::make('pages')->title('Pages')->save();

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

    $columns = collect($sets['articles']['fields'][0]['fields'])->keyBy('handle');

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
            ['field' => 'articles.title', 'weight' => 20],
            ['field' => 'articles.slug', 'weight' => 1],
            ['field' => 'pages.title', 'weight' => 10],
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

    expect($request->data())->toBe(['fields' => [['field' => 'products.title', 'weight' => 5]]]);
});

it('drops a disabled collection panel', function () {
    Http::fake(['*' => Http::response(['fields' => [], 'removed' => []])]);

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['_id' => 'a', 'type' => 'articles', 'enabled' => false, 'fields' => [
            ['_id' => 'r1', 'field' => 'title', 'weight' => '20'],
        ]],
        ['_id' => 'b', 'type' => 'pages', 'enabled' => true, 'fields' => [
            ['_id' => 'r2', 'field' => 'title', 'weight' => '10'],
        ]],
    ]])->assertOk();

    [$request] = Http::recorded()->first();

    expect($request->data())->toBe(['fields' => [['field' => 'pages.title', 'weight' => 10]]]);
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
    Queue::fake();

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
    Queue::fake();

    $this->patchJson(cp_route('search-service.fields.update'), ['collections' => [
        ['_id' => 'a', 'type' => 'articles', 'enabled' => true, 'fields' => [
            ['_id' => 'r1', 'field' => 'title', 'weight' => '20'],
        ]],
    ]])->assertUnprocessable();

    Queue::assertNotPushed(SyncCollection::class);
});
