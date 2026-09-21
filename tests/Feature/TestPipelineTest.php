<?php

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

uses(PreventsSavingStacheItemsToDisk::class);

beforeEach(function () {
    config([
        'search-service.url' => 'https://search.test',
        'search-service.key' => '1|secret',
    ]);

    $this->bard = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]];

    Collection::make('pages')->title('Pages')->save();
    Entry::make()->collection('pages')->id('about')->data([
        'title' => 'About',
        'content' => $this->bard,
        'featured' => true,
        'subtitle' => null,
    ])->save();

    $this->actingAs(User::make()->id('admin')->email('admin@alt.test')->makeSuper());
});

it('renders the test page with an entries field to pick from', function () {
    $this->get(cp_route('search-service.test'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('search-service::TestPipeline')
            ->has('meta.entry')
            ->where('blueprint.tabs.0.sections.0.fields.0.create', false)
            ->where('runUrl', cp_route('search-service.test.run')));
});

it('returns the document an entry sends without running it', function () {
    Http::fake();

    $this->getJson(cp_route('search-service.test.document', ['entry' => 'about']))
        ->assertOk()
        ->assertExactJson([
            'reference' => 'about',
            'fields' => ['title' => 'About', 'content' => json_encode($this->bard), 'featured' => '1'],
        ]);

    Http::assertNothingSent();
});

it('runs the entry through the pipeline and returns the result', function () {
    Http::fake(['search.test/api/evaluate' => Http::response([
        'reference' => 'about',
        'boost' => 0,
        'duration_ms' => 1.5,
        'errors' => [],
        'fields' => [['field' => 'content', 'value' => 'Hello from the pipeline', 'weight' => 20]],
    ])]);

    $this->postJson(cp_route('search-service.test.run'), ['entry' => 'about'])
        ->assertOk()
        ->assertJsonPath('fields.0.value', 'Hello from the pipeline');

    [$request] = Http::recorded()->first();

    expect($request->url())->toBe('https://search.test/api/evaluate')
        ->and($request->data())->toBe([
            'reference' => 'about',
            'fields' => ['title' => 'About', 'content' => json_encode($this->bard), 'featured' => '1'],
        ]);
});

it('returns the search service error when a run fails', function () {
    Http::fake(['*' => Http::response(['message' => 'Too Many Attempts.'], 429)]);

    $this->postJson(cp_route('search-service.test.run'), ['entry' => 'about'])
        ->assertOk()
        ->assertExactJson(['error' => 'Too Many Attempts.']);
});
