<?php

use AltDesign\SearchService\Jobs\SyncEntry;
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
});

function fakeService(array $fields = [['field' => 'articles.title', 'weight' => 10]]): void
{
    Http::fake([
        'search.test/api/fields' => Http::response(['fields' => $fields]),
        'search.test/api/index/*' => Http::response('', 204),
        'search.test/api/index' => Http::response(['queued' => 1], 202),
    ]);
}

function article(string $id, array $data = ['title' => 'Sofa'], bool $published = true)
{
    return tap(Entry::make()->collection('articles')->id($id)->slug($id)->published($published)->data($data))->save();
}

it('queues a sync job when an entry is saved', function () {
    Queue::fake();

    article('1');

    Queue::assertPushed(SyncEntry::class, fn (SyncEntry $job) => $job->reference === '1');
});

it('queues a sync job when an entry is deleted', function () {
    fakeService();
    $entry = article('1');
    Queue::fake();

    $entry->delete();

    Queue::assertPushed(SyncEntry::class, fn (SyncEntry $job) => $job->reference === '1');
});

it('queues nothing when the service is not configured', function () {
    config(['search-service.url' => null, 'search-service.key' => null]);
    Queue::fake();

    article('1');

    Queue::assertNotPushed(SyncEntry::class);
});

it('pushes a published entry in an active collection to the index', function () {
    Queue::fake();
    article('1', ['title' => 'Sofa', 'body' => 'A comfy sofa.']);
    fakeService();

    (new SyncEntry('1'))->handle();

    $posted = Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->first(fn ($request) => $request->method() === 'POST');

    expect($posted->url())->toBe('https://search.test/api/index')
        ->and($posted->data())->toBe(['documents' => [[
            'reference' => '1',
            'fields' => ['articles.title' => 'Sofa', 'articles.body' => 'A comfy sofa.'],
        ]]]);
});

it('removes an entry whose collection has no field configs', function () {
    Queue::fake();
    article('1');
    fakeService([['field' => 'pages.title', 'weight' => 10]]);

    (new SyncEntry('1'))->handle();

    $deleted = Http::recorded()->map(fn (array $pair) => $pair[0])->filter(fn ($request) => $request->method() === 'DELETE');

    expect($deleted)->toHaveCount(1)
        ->and($deleted->first()->url())->toBe('https://search.test/api/index/1');
});

it('removes a draft entry from the index', function () {
    Queue::fake();
    article('1', published: false);
    fakeService();

    (new SyncEntry('1'))->handle();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'https://search.test/api/index/1');
});

it('removes an entry that no longer exists', function () {
    fakeService();

    (new SyncEntry('gone'))->handle();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'https://search.test/api/index/gone');
});

it('throws when the service cannot be reached, so the job retries', function () {
    Queue::fake();
    article('1');
    Http::fake(['*' => Http::response([], 500)]);

    expect(fn () => (new SyncEntry('1'))->handle())->toThrow(RuntimeException::class);
});

it('sends nothing for an entry with no values', function () {
    Queue::fake();
    tap(Entry::make()->collection('articles')->id('1')->slug('1')->published(true))->save();
    fakeService();

    (new SyncEntry('1'))->handle();

    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});
