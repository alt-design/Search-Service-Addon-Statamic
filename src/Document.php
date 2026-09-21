<?php

namespace AltDesign\SearchService;

use Statamic\Contracts\Entries\Entry;

class Document
{
    /**
     * The document sent to the search service for an entry.
     *
     * @return array{reference: string, fields: array<string, string>}
     */
    public static function fromEntry(Entry $entry): array
    {
        return [
            'reference' => $entry->id(),
            'fields' => collect($entry->values())
                ->reject(fn ($value) => $value === null)
                // The service only processes strings, so structured values (Bard, replicator) go as JSON for decode_field
                ->map(fn ($value) => is_scalar($value) ? (string) $value : json_encode($value))
                ->all(),
        ];
    }
}
