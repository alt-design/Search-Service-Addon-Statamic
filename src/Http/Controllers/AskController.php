<?php

namespace AltDesign\SearchService\Http\Controllers;

use AltDesign\SearchService\Criteria;
use AltDesign\SearchService\Index;
use AltDesign\SearchService\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AskController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'filter' => ['sometimes', 'array', 'max:20'],
            'filter.*' => ['nullable'],
            'sort' => ['sometimes', 'string', 'max:64'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'facets' => ['sometimes', 'array', 'max:10'],
            'facets.*' => ['string', 'max:64'],
        ]);

        $limit = (int) ($data['limit'] ?? 10);
        $offset = (int) ($data['offset'] ?? 0);

        $result = Search::ask($data['q'], $limit, $offset, Criteria::fromRequest($data, fn (): array => Index::filterableAttributes()));

        if ($result === null) {
            return response()->json(['message' => 'Search is unavailable.'], 503);
        }

        return response()->json([
            'query' => $data['q'],
            'limit' => $limit,
            'offset' => $offset,
            'total' => $result['total'],
            'match' => $result['match'],
            'corrected' => $result['corrected'],
            'facets' => $result['facets'],
            'dropped' => $result['dropped'],
            'intent' => $result['intent'],
            'results' => $result['results']
                ->map(fn ($entry) => [
                    'reference' => (string) $entry->id(),
                    'score' => $entry->getSupplement('score'),
                    'title' => $entry->get('title'),
                    'url' => $entry->url(),
                    'collection' => $entry->collectionHandle(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
