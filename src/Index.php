<?php

namespace AltDesign\SearchService;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use RuntimeException;
use Statamic\Contracts\Entries\Entry;

/**
 * The site's index on the search service: which collections it covers, what it holds, and
 * the writes that change it.
 *
 * Every method throws when the service cannot be reached, so a queued job retries instead
 * of treating an outage as "nothing to index".
 */
class Index
{
    public const CACHE_KEY = 'search-service.active-collections';

    private const CACHE_SECONDS = 300;

    /**
     * Collection handles with at least one field config on the service. Configuring a
     * collection's fields is what activates it: a collection with no configs has nothing
     * stored against it, so a separate switch could only ever disagree with the service.
     *
     * @return list<string>
     */
    public static function activeCollections(): array
    {
        return Cache::remember(static::CACHE_KEY, static::CACHE_SECONDS, function (): array {
            $fields = static::send(fn () => Http::searchService()->get('fields'))->json('fields');

            return collect($fields)
                ->map(fn (array $field): string => Str::before($field['field'], '.'))
                ->unique()
                ->values()
                ->all();
        });
    }

    public static function isActive(?string $collection): bool
    {
        return $collection !== null && in_array($collection, static::activeCollections(), true);
    }

    /**
     * Drop the cached collection list, so the next sync sees a field config change.
     */
    public static function forget(): void
    {
        Cache::forget(static::CACHE_KEY);
    }

    /**
     * Send entries to the index in batches, returning the references sent. The service
     * queues each document, so this means accepted rather than indexed.
     *
     * An entry with no values is left out: the index endpoint requires at least one field,
     * so sending it would fail validation on every retry.
     *
     * @param  iterable<Entry>  $entries
     * @return list<string>
     */
    public static function push(iterable $entries): array
    {
        $sent = [];

        foreach (LazyCollection::make($entries)->chunk(static::batchSize()) as $batch) {
            $documents = $batch
                ->map(fn (Entry $entry): array => Document::fromEntry($entry))
                ->filter(fn (array $document): bool => $document['fields'] !== [])
                ->values()
                ->all();

            if ($documents === []) {
                continue;
            }

            static::send(fn () => Http::searchService()->post('index', ['documents' => $documents]));

            $sent = [...$sent, ...array_column($documents, 'reference')];
        }

        return $sent;
    }

    public static function delete(string $reference): void
    {
        static::send(fn () => Http::searchService()->delete("index/{$reference}"));
    }

    /**
     * Every reference the service holds for this site, paged through with the cursor it
     * returns.
     *
     * @return SupportCollection<int, string>
     */
    public static function references(): SupportCollection
    {
        $references = collect();
        $after = null;

        do {
            $response = static::send(fn () => Http::searchService()->get('index', array_filter(['after' => $after])));
            $page = collect($response->json('references'));

            $references = $references->concat($page);
            $after = $page->isEmpty() ? null : $response->json('next');
        } while ($after !== null);

        return $references->map(fn ($reference): string => (string) $reference)->values();
    }

    private static function batchSize(): int
    {
        return max(1, (int) config('search-service.batch_size'));
    }

    private static function send(callable $request): Response
    {
        $response = rescue($request, report: false);

        if (! $response?->successful()) {
            throw new RuntimeException($response
                ? "The search service returned HTTP {$response->status()}."
                : 'Could not reach the search service.');
        }

        return $response;
    }
}
