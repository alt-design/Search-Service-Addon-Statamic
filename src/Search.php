<?php

namespace AltDesign\SearchService;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    private const SEARCH_CACHE_PREFIX = 'search-service.search';

    private const ASK_CACHE_PREFIX = 'search-service.ask';

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
        $payload = static::payload(
            static::SEARCH_CACHE_PREFIX,
            $query,
            $limit,
            $offset,
            fn () => static::fetch($query, $limit, $offset),
        );

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
     * Run a natural language query and hydrate the page the service returns, alongside the
     * intent it inferred. Mirrors query() in every other respect, including returning null
     * when the service cannot be reached.
     *
     * Also returns null when the service answers 403, which is how it reports that the
     * site has not opted in to this feature: the page falls back rather than breaks, and a
     * warning is logged so a missing opt-in is diagnosable rather than a silent no-result.
     *
     * @return array{total: int, results: EntryCollection, match: string, corrected: ?string, intent: array{source: string, terms: array<int, string>, concepts: array<int, array{facet: string, value: string}>, unmatched: array<int, array{facet: string, value: string}>}}|null
     */
    public static function ask(string $query, int $limit = 10, int $offset = 0): ?array
    {
        $payload = static::payload(
            static::ASK_CACHE_PREFIX,
            $query,
            $limit,
            $offset,
            fn () => static::fetchAsk($query, $limit, $offset),
        );

        if ($payload === null) {
            return null;
        }

        return [
            'total' => $payload['total'],
            'results' => static::hydrate($payload['results']),
            'match' => $payload['match'],
            'corrected' => $payload['corrected'],
            'intent' => $payload['intent'],
        ];
    }

    /**
     * The service's raw response for this query, limit and offset, cached briefly so a
     * burst of identical requests only reaches the service once. A failed request is
     * never cached, so the next call tries the service again rather than repeating null.
     *
     * @param  callable(): (array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string}|array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string, intent: array{source: string, terms: array<int, string>, concepts: array<int, array{facet: string, value: string}>, unmatched: array<int, array{facet: string, value: string}>}}|null)  $fetcher
     * @return array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string}|array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string, intent: array{source: string, terms: array<int, string>, concepts: array<int, array{facet: string, value: string}>, unmatched: array<int, array{facet: string, value: string}>}}|null
     */
    private static function payload(string $prefix, string $query, int $limit, int $offset, callable $fetcher): ?array
    {
        $seconds = (int) config('search-service.search_cache_seconds');

        if ($seconds <= 0) {
            return $fetcher();
        }

        $key = static::cacheKey($prefix, $query, $limit, $offset);

        $payload = Cache::get($key);

        if ($payload !== null) {
            return $payload;
        }

        $payload = $fetcher();

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

    /**
     * A 403 means the site has not opted in to the ask feature, which is reported
     * separately from any other failure so the caller can fall back quietly while the
     * cause stays visible in the logs.
     *
     * @return array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string, intent: array{source: string, terms: array<int, string>, concepts: array<int, array{facet: string, value: string}>, unmatched: array<int, array{facet: string, value: string}>}}|null
     */
    private static function fetchAsk(string $query, int $limit, int $offset): ?array
    {
        $response = rescue(fn () => Http::searchService()->post('ask', [
            'q' => $query,
            'limit' => $limit,
            'offset' => $offset,
        ]), report: false);

        if ($response?->status() === 403) {
            Log::warning('Search service rejected an ask request: site has not opted in to intent search.');

            return null;
        }

        if (! $response?->successful()) {
            return null;
        }

        $corrected = $response->json('corrected');
        $intent = $response->json('intent') ?? [];

        return [
            'total' => (int) $response->json('total', 0),
            'results' => $response->json('results') ?? [],
            'match' => (string) $response->json('match', 'intent'),
            'corrected' => $corrected === null ? null : (string) $corrected,
            'intent' => [
                'source' => (string) ($intent['source'] ?? 'fallback'),
                'terms' => $intent['terms'] ?? [],
                'concepts' => $intent['concepts'] ?? [],
                'unmatched' => $intent['unmatched'] ?? [],
            ],
        ];
    }

    private static function cacheKey(string $prefix, string $query, int $limit, int $offset): string
    {
        return $prefix.'.'.md5($query.'|'.$limit.'|'.$offset);
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
