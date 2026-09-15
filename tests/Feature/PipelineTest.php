<?php

use Illuminate\Support\Facades\Http;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;

uses(FakesRoles::class);

beforeEach(function () {
    config([
        'search-service.url' => 'https://search.test',
        'search-service.key' => '1|secret',
    ]);

    $this->actingAs(User::make()->id('admin')->email('admin@alt.test')->makeSuper());
});

it('loads the pipeline from the search service into the editor', function () {
    Http::fake(['search.test/api/pipeline' => Http::response(['steps' => [
        ['transformer' => 'decode_field', 'enabled' => true, 'config' => ['format' => 'tiptap', 'fields' => ['body']]],
        ['transformer' => 'adjust_weights', 'enabled' => false, 'config' => ['weights' => ['title' => 20]]],
    ]])]);

    $this->getJson(cp_route('search-service.pipeline.edit'))
        ->assertOk()
        ->assertJsonPath('values.steps.0.type', 'decode_field')
        ->assertJsonPath('values.steps.0.format', 'tiptap')
        ->assertJsonPath('values.steps.0.fields', ['body'])
        ->assertJsonPath('values.steps.1.enabled', false)
        ->assertJsonPath('values.steps.1.weights.0.field', 'title')
        ->assertJsonPath('values.steps.1.weights.0.weight', 20);
});

it('saves the edited pipeline to the search service', function () {
    Http::fake(['*' => Http::response(['steps' => []])]);

    $this->patchJson(cp_route('search-service.pipeline.update'), ['steps' => [
        ['_id' => 'a', 'type' => 'decode_field', 'enabled' => true, 'format' => 'tiptap', 'fields' => ['body']],
        ['_id' => 'b', 'type' => 'trim', 'enabled' => false, 'fields' => []],
        ['_id' => 'c', 'type' => 'adjust_weights', 'enabled' => true, 'weights' => [['_id' => 'd', 'field' => 'title', 'weight' => '20']]],
    ]])->assertOk()->assertJson(['saved' => true]);

    [$request] = Http::recorded()->first();
    $sent = json_decode($request->body(), true);

    expect($request->method())->toBe('PUT')
        ->and($request->url())->toBe('https://search.test/api/pipeline')
        ->and($request->body())->toContain('"config":{}')
        ->and($sent)->toEqual(['steps' => [
            ['transformer' => 'decode_field', 'enabled' => true, 'config' => ['format' => 'tiptap', 'fields' => ['body']]],
            ['transformer' => 'trim', 'enabled' => false, 'config' => []],
            ['transformer' => 'adjust_weights', 'enabled' => true, 'config' => ['weights' => ['title' => 20]]],
        ]])
        ->and($sent['steps'][2]['config']['weights']['title'])->toBe(20);
});

it('rejects an invalid step without calling the search service', function () {
    Http::fake();

    $this->patchJson(cp_route('search-service.pipeline.update'), ['steps' => [
        ['_id' => 'a', 'type' => 'decode_field', 'enabled' => true],
    ]])->assertUnprocessable()->assertJsonValidationErrors('steps.0.format');

    Http::assertNothingSent();
});

it('shows the search service error when it rejects the pipeline', function () {
    Http::fake(['*' => Http::response(['message' => 'The steps.0.config.format field is invalid.'], 422)]);

    $this->patchJson(cp_route('search-service.pipeline.update'), ['steps' => [
        ['_id' => 'a', 'type' => 'decode_field', 'enabled' => true, 'format' => 'json'],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['steps' => 'The steps.0.config.format field is invalid.']);
});

it('refuses to edit a pipeline with steps this addon does not support', function () {
    Http::fake(['*' => Http::response(['steps' => [
        ['transformer' => 'something_new', 'enabled' => true, 'config' => []],
    ]])]);

    $this->get(cp_route('search-service.pipeline.edit'))
        ->assertRedirect(cp_route('search-service.index'))
        ->assertSessionHas('error');
});

it('only lets users with the edit permission save', function () {
    Http::fake();
    $this->setTestRoles(['viewer' => ['access cp', 'view search-service']]);

    $this->actingAs(User::make()->id('viewer')->email('viewer@alt.test')->assignRole('viewer'))
        ->patchJson(cp_route('search-service.pipeline.update'), ['steps' => []])
        ->assertForbidden();

    Http::assertNothingSent();
});
