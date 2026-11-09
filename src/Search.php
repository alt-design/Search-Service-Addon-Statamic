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
     * @return array{total: int, results: EntryCollection, match: string, corrected: ?string, facets: array<string, list<array{value: string, total: int}>>}|null
     */
    public static function query(string $query, int $limit = 10, int $offset = 0, ?Criteria $criteria = null): ?array
    {
        $criteria ??= Criteria::none();

        $payload = static::payload(
            static::SEARCH_CACHE_PREFIX,
            $query,
            $limit,
            $offset,
            $criteria,
            fn () => static::fetch($query, $limit, $offset, $criteria),
        );

        if ($payload === null) {
            return null;
        }

        return [
            'total' => $payload['total'],
            'results' => static::hydrate($payload['results']),
            'match' => $payload['match'],
            'corrected' => $payload['corrected'],
            'facets' => $payload['facets'],
        ];
    }

    /**
     * Run a natural language query and hydrate the page the service returns, alongside the
     * intent it inferred. Mirrors query() in every other respect, including returning null
     * when the service cannot be reached.
     *
     * The dropped meaning is set when nothing satisfied the whole query and the service
     * had to give one part of it up to answer, which is what lets a page say it is showing
     * red furniture rather than the red chair nobody has.
     *
     * Also returns null when the service answers 403, which is how it reports that the
     * site has not opted in to this feature: the page falls back rather than breaks, and a
     * warning is logged so a missing opt-in is diagnosable rather than a silent no-result.
     *
     * @return array{total: int, results: EntryCollection, match: string, corrected: ?string, facets: array<string, list<array{value: string, total: int}>>, dropped: ?array{facet: string, value: string}, intent: array{source: string, terms: array<int, string>, corrected: ?string, concepts: array<int, array{facet: string, value: string}>, unmatched: array<int, array{facet: string, value: string}>}}|null
     */
    public static function ask(string $query, int $limit = 10, int $offset = 0, ?Criteria $criteria = null, ?Chosen $chosen = null): ?array
    {
        $criteria ??= Criteria::none();
        $chosen ??= Chosen::none();

        $payload = static::payload(
            static::ASK_CACHE_PREFIX,
            $query,
            $limit,
            $offset,
            $criteria,
            fn () => static::fetchAsk($query, $limit, $offset, $criteria, $chosen),
            $chosen,
        );

        if ($payload === null) {
            return null;
        }

        return [
            'total' => $payload['total'],
            'results' => static::hydrate($payload['results']),
            'match' => $payload['match'],
            'corrected' => $payload['corrected'],
            'facets' => $payload['facets'],
            'dropped' => $payload['dropped'],
            'intent' => $payload['intent'],
            'chosen' => $payload['chosen'] ?? [],
            'question' => $payload['question'] ?? null,
        ];
    }

    /**
     * One turn of a guided conversation.
     *
     * Deliberately not cached, unlike a search. A turn is a reading of what somebody just
     * typed at the point they typed it, and the state it returns is what the next turn
     * depends on, so serving a stored one would answer the wrong question convincingly.
     * The service caches its own reading of a message, which is where the saving belongs.
     *
     * Null means the service could not answer. A page should keep the visitor where they
     * are and say so, rather than treating it as being sent off path: somebody with a
     * real question told to go and use the contact form, because a rate limit was
     * reached, is a worse failure than an apology.
     *
     * @return array{key: string, name: string, prompt: ?string, node: ?string, state: array{node: ?string, answers: list<array{facet: string, value: string}>}, question: ?array{facet: string, options: array<int, array{value: string, total: int}>}, sorted: ?array{name: string, direction: string}, handoff: ?array{url: ?string, message: ?string}, total: int, facets: array<string, list<array{value: string, total: int}>>, results: EntryCollection}|null
     */
    public static function journey(string $key, string $message = '', ?JourneyState $state = null, int $limit = 10): ?array
    {
        $state ??= JourneyState::start();

        $response = rescue(fn () => Http::searchService()->post('journey', array_filter([
            'key' => $key,
            'message' => $message,
            'limit' => $limit,
            'state' => $state->toQuery(),
        ], fn (mixed $value): bool => $value !== '' && $value !== [])), report: false);

        if ($response?->status() === 404) {
            Log::warning('Search service has no journey by that key for this site.', ['key' => $key]);

            return null;
        }

        if ($response?->status() === 403) {
            Log::warning('Search service will not serve that journey: it is not ready to be used.', ['key' => $key]);

            return null;
        }

        if (! $response?->successful()) {
            return null;
        }

        $handoff = $response->json('handoff');
        $prompt = $response->json('prompt');
        $node = $response->json('node');
        $sorted = $response->json('sorted');
        $dropped = $response->json('dropped');

        return [
            'key' => (string) $response->json('key', $key),
            'name' => (string) $response->json('name', ''),
            'prompt' => $prompt === null ? null : (string) $prompt,
            'node' => $node === null ? null : (string) $node,
            'state' => JourneyState::from($response->json('state') ?? [])->toQuery(),
            'question' => is_array($question = $response->json('question')) ? $question : null,
            'sorted' => is_array($sorted) ? [
                'name' => (string) ($sorted['name'] ?? ''),
                'direction' => (string) ($sorted['direction'] ?? 'asc'),
            ] : null,
            // What the service had to give up to answer at all, so a page can
            // say there is no such thing rather than show the nearest thing
            // and leave somebody believing they asked for it.
            'dropped' => is_array($dropped) ? [
                'facet' => (string) ($dropped['facet'] ?? ''),
                'value' => (string) ($dropped['value'] ?? ''),
            ] : null,
            'handoff' => is_array($handoff) ? [
                'url' => isset($handoff['url']) ? (string) $handoff['url'] : null,
                'message' => isset($handoff['message']) ? (string) $handoff['message'] : null,
            ] : null,
            'total' => (int) $response->json('total', 0),
            'facets' => $response->json('facets') ?? [],
            'results' => static::hydrate($response->json('results') ?? []),
        ];
    }

    /**
     * The service's raw response for this query, limit and offset, cached briefly so a
     * burst of identical requests only reaches the service once. A failed request is
     * never cached, so the next call tries the service again rather than repeating null.
     *
     * @param  callable(): (array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string, facets: array<string, list<array{value: string, total: int}>>}|array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string, facets: array<string, list<array{value: string, total: int}>>, dropped: ?array{facet: string, value: string}, intent: array{source: string, terms: array<int, string>, corrected: ?string, concepts: array<int, array{facet: string, value: string}>, unmatched: array<int, array{facet: string, value: string}>}}|null)  $fetcher
     * @return array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string, facets: array<string, list<array{value: string, total: int}>>}|array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string, facets: array<string, list<array{value: string, total: int}>>, dropped: ?array{facet: string, value: string}, intent: array{source: string, terms: array<int, string>, corrected: ?string, concepts: array<int, array{facet: string, value: string}>, unmatched: array<int, array{facet: string, value: string}>}}|null
     */
    private static function payload(string $prefix, string $query, int $limit, int $offset, Criteria $criteria, callable $fetcher, ?Chosen $chosen = null): ?array
    {
        $seconds = (int) config('search-service.search_cache_seconds');

        if ($seconds <= 0) {
            return $fetcher();
        }

        $key = static::cacheKey($prefix, $query, $limit, $offset, $criteria, $chosen);

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
     * @return array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string, facets: array<string, list<array{value: string, total: int}>>}|null
     */
    private static function fetch(string $query, int $limit, int $offset, Criteria $criteria): ?array
    {
        $response = rescue(fn () => Http::searchService()->get('search', [
            'q' => $query,
            'limit' => $limit,
            'offset' => $offset,
            ...$criteria->toQuery(),
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
            'facets' => $response->json('facets') ?? [],
        ];
    }

    /**
     * A 403 means the site has not opted in to the ask feature, which is reported
     * separately from any other failure so the caller can fall back quietly while the
     * cause stays visible in the logs.
     *
     * @return array{total: int, results: array<int, array{reference: string, score: float}>, match: string, corrected: ?string, facets: array<string, list<array{value: string, total: int}>>, dropped: ?array{facet: string, value: string}, intent: array{source: string, terms: array<int, string>, corrected: ?string, concepts: array<int, array{facet: string, value: string}>, unmatched: array<int, array{facet: string, value: string}>}}|null
     */
    private static function fetchAsk(string $query, int $limit, int $offset, Criteria $criteria, Chosen $chosen): ?array
    {
        $response = rescue(fn () => Http::searchService()->post('ask', array_filter([
            'q' => $query,
            'limit' => $limit,
            'offset' => $offset,
            'chosen' => $chosen->toQuery(),
            ...$criteria->toQuery(),
        ], fn (mixed $value): bool => $value !== [])), report: false);

        if ($response?->status() === 403) {
            Log::warning('Search service rejected an ask request: site has not opted in to intent search.');

            return null;
        }

        if (! $response?->successful()) {
            return null;
        }

        $corrected = $response->json('corrected');
        $dropped = $response->json('dropped');
        $intent = $response->json('intent') ?? [];

        return [
            'total' => (int) $response->json('total', 0),
            'results' => $response->json('results') ?? [],
            'match' => (string) $response->json('match', 'intent'),
            'corrected' => $corrected === null ? null : (string) $corrected,
            'facets' => $response->json('facets') ?? [],
            'dropped' => is_array($dropped) ? [
                'facet' => (string) ($dropped['facet'] ?? ''),
                'value' => (string) ($dropped['value'] ?? ''),
            ] : null,
            'chosen' => $response->json('chosen') ?? [],
            'question' => is_array($question = $response->json('question')) ? $question : null,
            'intent' => [
                'source' => (string) ($intent['source'] ?? 'fallback'),
                'terms' => $intent['terms'] ?? [],
                'corrected' => isset($intent['corrected']) ? (string) $intent['corrected'] : null,
                'concepts' => $intent['concepts'] ?? [],
                'unmatched' => $intent['unmatched'] ?? [],
            ],
        ];
    }

    /**
     * Filters, sorting and facets are part of the key, not a detail of it: a page asking
     * for blue must not be served the cached answer for everything.
     */
    private static function cacheKey(string $prefix, string $query, int $limit, int $offset, Criteria $criteria, ?Chosen $chosen = null): string
    {
        $chosen ??= Chosen::none();

        return $prefix.'.'.md5($query.'|'.$limit.'|'.$offset.'|'.$criteria->fingerprint().'|'.$chosen->fingerprint());
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
