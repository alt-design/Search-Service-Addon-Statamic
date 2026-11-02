<?php

namespace AltDesign\SearchService;

use Statamic\Contracts\Entries\Entry;

class Document
{
    /**
     * The document sent to the search service for an entry. Field names carry the entry's
     * collection, so two collections sharing a field handle get their own weight.
     *
     * A field configured as an attribute goes in "attributes" as well, under its bare
     * handle: an attribute is what a visitor filters and sorts by, so "price" is the name
     * worth having in a URL, and the service treats the names as one flat set per site
     * rather than per collection.
     *
     * As well, not instead. Tags are the case that settles it: the words belong in the
     * index so a search for velvet finds a velvet article, and the same values belong in
     * the attributes so a visitor can narrow to them. Whether the text is searched is the
     * field's enabled flag, which is a separate question answered on the service.
     *
     * Attribute values keep the type they were stored with, because that is what the
     * service reads them as: a toggle has to stay true rather than becoming "1", and a
     * price has to stay a number or it sorts as a word.
     *
     * @param  list<string>  $attributeFields  configured as "collection.handle"
     * @return array{reference: string, fields: array<string, string>, attributes: array<string, mixed>}
     */
    public static function fromEntry(Entry $entry, array $attributeFields = []): array
    {
        $collection = $entry->collectionHandle();

        $values = collect($entry->values())->reject(fn ($value) => $value === null);

        $isAttribute = fn (string $handle): bool => in_array("{$collection}.{$handle}", $attributeFields, true);

        return [
            'reference' => $entry->id(),
            'fields' => $values
                // Structured values (Bard, replicator) go as JSON for decode_field to unpack. The
                // service casts an undecoded value with (string), which turns a raw array into "Array".
                ->mapWithKeys(fn ($value, string $handle) => [
                    "{$collection}.{$handle}" => is_scalar($value) ? (string) $value : json_encode($value),
                ])
                ->all(),
            'attributes' => $values
                ->filter(fn ($value, string $handle): bool => $isAttribute($handle))
                ->map(fn ($value) => self::attributeValue($value))
                ->reject(fn ($value) => $value === null)
                ->all(),
        ];
    }

    /**
     * A value the service can read as something typed, or null when it cannot.
     *
     * A list is kept as one, since that is how a document carries two colours, but only
     * its scalar members: an array of arrays has no single value to filter by.
     */
    private static function attributeValue(mixed $value): string|int|float|bool|array|null
    {
        if (is_array($value)) {
            $scalars = array_values(array_filter($value, 'is_scalar'));

            return $scalars === [] ? null : $scalars;
        }

        return is_scalar($value) ? $value : null;
    }
}
