<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

uses(PreventsSavingStacheItemsToDisk::class);

beforeEach(function () {
    config([
        'search-service.url' => 'https://search.test',
        'search-service.key' => '1|secret',
        'search-service.batch_size' => 50,
    ]);

    Collection::make('articles')->title('Articles')->save();
    Collection::make('pages')->title('Pages')->save();

    Queue::fake();
});

/**
 * @param  list<array{field: string, weight: int}>  $fields
 * @param  list<string>  $stored  references the service already holds
 */
function fakeSyncService(array $fields = [['field' => 'articles.title', 'weight' => 10]], array $stored = []): void
{
    Http::fake(fn ($request) => match (true) {
        str_contains($request->url(), '/api/fields') => Http::response(['fields' => $fields]),
        $request->method() === 'GET' => Http::response(['references' => $stored, 'next' => null]),
        $request->method() === 'DELETE' => Http::response('', 204),
        default => Http::response(['queued' => 1], 202),
    });
}

function syncEntry(string $collection, string $id, bool $published = true): void
{
    Entry::make()->collection($collection)->id($id)->slug($id)->published($published)->data(['title' => "Title {$id}"])->save();
}

/**
 * @return \Illuminate\Support\Collection<int, \Illuminate\Http\Client\Request>
 */
function requestsSent(string $method): \Illuminate\Support\Collection
{
    return Http::recorded()->map(fn (array $pair) => $pair[0])->filter(fn ($request) => $request->method() === $method)->values();
}

it('sends every published entry of the active collections, in batches', function () {
    config(['search-service.batch_size' => 2]);
    syncEntry('articles', '1');
    syncEntry('articles', '2');
    syncEntry('articles', '3');
    fakeSyncService();

    $this->artisan('statamic:search-service:sync')->assertSuccessful();

    $batches = requestsSent('POST')->map(fn ($request) => collect($request->data()['documents'])->pluck('reference')->all());

    expect($batches)->toHaveCount(2)
        ->and($batches->flatten()->sort()->values()->all())->toBe(['1', '2', '3'])
        ->and($batches->first())->toHaveCount(2);
});

it('does not send a draft entry', function () {
    syncEntry('articles', '1');
    syncEntry('articles', '2', published: false);
    fakeSyncService();

    $this->artisan('statamic:search-service:sync')->assertSuccessful();

    expect(requestsSent('POST')->first()->data()['documents'])->toHaveCount(1);
});

it('leaves an inactive collection alone', function () {
    syncEntry('articles', '1');
    syncEntry('pages', '2');
    fakeSyncService();

    $this->artisan('statamic:search-service:sync')->assertSuccessful();

    expect(collect(requestsSent('POST')->first()->data()['documents'])->pluck('reference')->all())->toBe(['1']);
});

it('deletes references the site no longer has', function () {
    syncEntry('articles', '1');
    fakeSyncService(stored: ['1', 'deleted-entry']);

    $this->artisan('statamic:search-service:sync')->assertSuccessful();

    expect(requestsSent('DELETE')->map->url()->all())->toBe(['https://search.test/api/index/deleted-entry']);
});

it('prunes nothing when the sync is limited to named collections', function () {
    syncEntry('articles', '1');
    fakeSyncService(stored: ['1', 'deleted-entry']);

    $this->artisan('statamic:search-service:sync', ['--collection' => ['articles']])->assertSuccessful();

    expect(requestsSent('DELETE'))->toBeEmpty();
});

it('fails when a named collection is not active on the service', function () {
    fakeSyncService();

    $this->artisan('statamic:search-service:sync', ['--collection' => ['pages']])->assertFailed();

    expect(requestsSent('POST'))->toBeEmpty();
});

it('fails without sending anything when the service is not configured', function () {
    config(['search-service.url' => null, 'search-service.key' => null]);
    Http::fake();

    $this->artisan('statamic:search-service:sync')->assertFailed();

    Http::assertNothingSent();
});

it('does nothing when no collection has field configs', function () {
    syncEntry('articles', '1');
    fakeSyncService(fields: []);

    $this->artisan('statamic:search-service:sync')->assertSuccessful();

    expect(requestsSent('POST'))->toBeEmpty();
});

it('fails when the service cannot be reached', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $this->artisan('statamic:search-service:sync')->assertFailed();
});
