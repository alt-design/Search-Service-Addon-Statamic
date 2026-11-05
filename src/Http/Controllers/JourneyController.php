<?php

namespace AltDesign\SearchService\Http\Controllers;

use AltDesign\SearchService\JourneyState;
use AltDesign\SearchService\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JourneyController
{
    /**
     * How many extra fields one request may ask for, which bounds the size of
     * a response rather than what a page is allowed to know: these are the
     * site's own entries and it could fetch them anyway.
     */
    private const MAX_FIELDS = 8;

    /**
     * One turn of a guided conversation, for a page driving it from the
     * browser rather than through the tag.
     *
     * The state goes out as the encoded parameter rather than as an object,
     * which is what the caller sends back next turn and what a link in the
     * page holds, so neither has to understand how it is put together.
     *
     * A 503 means the service could not answer. The caller should leave the
     * visitor where they are and say so, rather than treat it as a handoff.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'message' => ['sometimes', 'nullable', 'string', 'max:500'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'state' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'fields' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $fields = $this->fields($data['fields'] ?? null);

        $turn = Search::journey(
            $data['key'],
            trim((string) ($data['message'] ?? '')),
            JourneyState::from($data['state'] ?? null),
            (int) ($data['limit'] ?? 10),
        );

        if ($turn === null) {
            return response()->json(['message' => 'That journey is unavailable.'], 503);
        }

        return response()->json([
            'key' => $turn['key'],
            'name' => $turn['name'],
            'prompt' => $turn['prompt'],
            'node' => $turn['node'],
            'state' => JourneyState::from($turn['state'])->parameter(),
            'question' => $turn['question'],
            'sorted' => $turn['sorted'],
            'handoff' => $turn['handoff'],
            'total' => $turn['total'],
            'facets' => $turn['facets'],
            'results' => $turn['results']
                ->map(fn ($entry) => [
                    'reference' => (string) $entry->id(),
                    'score' => $entry->getSupplement('score'),
                    'title' => $entry->get('title'),
                    'url' => $entry->url(),
                    'collection' => $entry->collectionHandle(),
                    'values' => $this->valuesOf($entry, $fields),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * The entry fields a caller asked to have alongside each result.
     *
     * A page rendering results in JavaScript needs more than a title: a price,
     * a summary, whatever it shows on a card. The tag hands over whole entries
     * and has no such problem, so this is the JSON endpoint catching up.
     *
     * Named by the caller rather than guessed at here, because what belongs on
     * a card is a question about that site and not about journeys.
     *
     * @return list<string>
     */
    private function fields(?string $fields): array
    {
        if ($fields === null || trim($fields) === '') {
            return [];
        }

        $named = array_filter(array_map('trim', explode(',', $fields)));

        return array_slice(array_values(array_unique($named)), 0, self::MAX_FIELDS);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function valuesOf(mixed $entry, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $value = $entry->value($field);

            if (is_scalar($value)) {
                $values[$field] = $value;
            }
        }

        return $values;
    }
}
