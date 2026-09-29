<?php

namespace AltDesign\SearchService;

use Statamic\Contracts\Entries\Entry;

class Document
{
    /**
     * The document sent to the search service for an entry. Field names carry the entry's
     * collection, so two collections sharing a field handle get their own weight.
     *
     * @return array{reference: string, fields: array<string, string>}
     */
    public static function fromEntry(Entry $entry): array
    {
        $collection = $entry->collectionHandle();

        return [
            'reference' => $entry->id(),
            'fields' => collect($entry->values())
                ->reject(fn ($value) => $value === null)
                // Structured values (Bard, replicator) go as JSON for decode_field to unpack. The
                // service casts an undecoded value with (string), which turns a raw array into "Array".
                ->mapWithKeys(fn ($value, string $handle) => [
                    "{$collection}.{$handle}" => is_scalar($value) ? (string) $value : json_encode($value),
                ])
                ->all(),
        ];
    }
}
