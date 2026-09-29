<?php

namespace AltDesign\SearchService;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Statamic\Entries\EntryCollection;
use Statamic\Facades\Entry;

/**
 * Searching the service, and turning the references it returns back into entries.
 *
 * The service stores references and scores rather than content, so the entries come from
 * this site and the API key never reaches the browser.
 */
class Search
{
    private const CACHE_PREFIX = 'search-service.search';

    /**
     * Run a query and hydrate the page the service returns, or null when the service
     * cannot be reached, which callers report differently: the endpoint answers 503, the
     * tag renders nothing.
     *
     * The total counts what the service matched, so it can exceed the entries returned: a
     * reference is dropped when it no longer resolves here or is not published, which
     * makes a page render short rather than wrong.
     *
     * The match mode defaults to exact when the service does not send one, so the addon
     * keeps working against an older service that predates tiered matching.
     *
     * The corrected query is null when the service does not send one, including a
     * correction-unaware match mode and an older service that predates correction.
     *
     * Entries are resolved fresh on every call, cached or not, so a cached hit still
     * reflects an entry published or unpublished since the payload was stored.
     *
     * @return array{total: int, results: EntryCollection, match: string, corrected: ?string}|null
     */
    public static function query(string $query, int $limit = 10, int $offset = 0): ?array
    {
        $payload = static::payload($query, $limit, $offset);

        if ($payload === null) {
            return null;
        }

        return [
            'total' => $payload['total'],
            'results' => static::hydrate($payload['results']),
            'match' => $payload['match'],
            'corrected' => $payload['corrected'],
        ];
    }

    /**
     * The service's raw response for this query, limit and offset, cached briefly so a
     * burst of identical requests only reaches the service once. A failed request is
     * never cached, so the next call tries the service again rather than repeating null.
     *
     * @return array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string}|null
     */
    private static function payload(string $query, int $limit, int $offset): ?array
    {
        $seconds = (int) config('search-service.search_cache_seconds');

        if ($seconds <= 0) {
            return static::fetch($query, $limit, $offset);
        }

        $key = static::cacheKey($query, $limit, $offset);

        $payload = Cache::get($key);

        if ($payload !== null) {
            return $payload;
        }

        $payload = static::fetch($query, $limit, $offset);

        if ($payload !== null) {
            Cache::put($key, $payload, $seconds);
        }

        return $payload;
    }

    /**
     * @return array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string}|null
     */
    private static function fetch(string $query, int $limit, int $offset): ?array
    {
        $response = rescue(fn () => Http::searchService()->get('search', [
            'q' => $query,
            'limit' => $limit,
            'offset' => $offset,
        ]), report: false);

        if (! $response?->successful()) {
            return null;
        }

        $corrected = $response->json('corrected');

        return [
            'total' => (int) $response->json('total', 0),
            'results' => $response->json('results') ?? [],
            'match' => (string) $response->json('match', 'exact'),
            'corrected' => $corrected === null ? null : (string) $corrected,
        ];
    }

    private static function cacheKey(string $query, int $limit, int $offset): string
    {
        return static::CACHE_PREFIX.'.'.md5($query.'|'.$limit.'|'.$offset);
    }

    /**
     * Status rather than the published flag, so a scheduled or expired entry cannot
     * surface in results, matching what the sync sends to the index.
     *
     * @param  array<int, array{reference: string, score: float}>  $results
     */
    private static function hydrate(array $results): EntryCollection
    {
        return new EntryCollection(
            collect($results)
                ->map(function (array $result) {
                    $entry = Entry::find($result['reference']);

                    return $entry && $entry->status() === 'published'
                        ? $entry->setSupplement('score', $result['score'])
                        : null;
                })
                ->filter()
                ->values()
        );
    }
}
