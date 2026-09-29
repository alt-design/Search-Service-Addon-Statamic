<?php

namespace AltDesign\SearchService;

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
    /**
     * Run a query and hydrate the page the service returns, or null when the service
     * cannot be reached, which callers report differently: the endpoint answers 503, the
     * tag renders nothing.
     *
     * The total counts what the service matched, so it can exceed the entries returned: a
     * reference is dropped when it no longer resolves here or is not published, which
     * makes a page render short rather than wrong.
     *
     * @return array{total: int, results: EntryCollection}|null
     */
    public static function query(string $query, int $limit = 10, int $offset = 0): ?array
    {
        $response = rescue(fn () => Http::searchService()->get('search', [
            'q' => $query,
            'limit' => $limit,
            'offset' => $offset,
        ]), report: false);

        if (! $response?->successful()) {
            return null;
        }

        return [
            'total' => (int) $response->json('total', 0),
            'results' => static::hydrate($response->json('results') ?? []),
        ];
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
