<?php

namespace AltDesign\SearchService;

use Illuminate\Support\Str;
use Statamic\Entries\Collection as EntryCollection;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Fields\Blueprint as FieldsBlueprint;

/**
 * The field config editor: an accordion of collections, each holding the fields it indexes
 * and their weights, mapped to and from the search service's fields API (docs/api/fields.md).
 *
 * Field configs on the service are flat and per site, so a field carries its collection in
 * its name: "articles.title". That is this addon's convention, not a service rule.
 */
class Fields
{
    /**
     * Set handle for configs whose collection this site does not have, which is what a
     * deleted collection or a hand-written config leaves behind. Saving replaces the whole
     * list, so they get a panel of their own rather than being destroyed by a save that
     * could not show them. No collection handle starts with an underscore.
     */
    public const ORPHANED = '_orphaned';

    public static function blueprint(): FieldsBlueprint
    {
        return Blueprint::make('search-service-fields')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [[
                'handle' => 'collections',
                'field' => [
                    'type' => 'replicator',
                    'display' => 'Collections',
                    'instructions' => 'Only the fields listed here are indexed, and a field weighted higher ranks its matches higher. Removing a field, or a whole collection, deletes the values already stored for it on every entry, and only a full reindex brings them back.',
                    'collapse' => 'accordion',
                    'button_label' => 'Add collection',
                    'previews' => false,
                    'sets' => static::sets(),
                ],
            ]]]]]],
        ]);
    }

    /**
     * API field list to editor values, grouped into a panel per collection.
     *
     * @param  list<array{field: string, weight: int}>  $fields
     */
    public static function toValues(array $fields): array
    {
        $handles = Collection::handles()->all();

        return ['collections' => collect($fields)
            ->groupBy(function (array $field) use ($handles): string {
                $collection = Str::before($field['field'], '.');

                return in_array($collection, $handles, true) ? $collection : static::ORPHANED;
            })
            ->map(fn ($group, string $set) => [
                'type' => $set,
                'enabled' => true,
                'fields' => $group->map(fn (array $field) => [
                    'field' => $set === static::ORPHANED ? $field['field'] : Str::after($field['field'], '.'),
                    'weight' => $field['weight'],
                ])->values()->all(),
            ])
            ->values()
            ->all()];
    }

    /**
     * Processed editor values to an API field list. A disabled panel is dropped, which the
     * editor's instructions warn deletes its stored values, because the service has no
     * paused state: a field either has a config or has nothing stored.
     *
     * @return list<array{field: string, weight: int}>
     */
    public static function toFields(array $values): array
    {
        return collect($values['collections'] ?? [])
            ->filter(fn (array $set) => $set['enabled'] ?? true)
            ->flatMap(fn (array $set) => collect($set['fields'] ?? [])->map(fn (array $row) => [
                'field' => $set['type'] === static::ORPHANED ? $row['field'] : "{$set['type']}.{$row['field']}",
                'weight' => (int) $row['weight'],
            ]))
            ->values()
            ->all();
    }

    private static function sets(): array
    {
        $collections = Collection::all()->mapWithKeys(fn (EntryCollection $collection) => [
            $collection->handle() => [
                'display' => $collection->title(),
                'fields' => [static::grid(static::fieldOptions($collection))],
            ],
        ])->all();

        return [
            ...$collections,
            static::ORPHANED => [
                'display' => 'Other fields',
                'instructions' => 'Fields configured on the search service that belong to no collection here, usually because the collection was deleted. Remove one to stop it being indexed.',
                'fields' => [static::grid(null)],
            ],
        ];
    }

    /**
     * Null options means the field name is typed rather than picked, which is the only
     * option when there is no blueprint to read it from.
     */
    private static function grid(?array $options): array
    {
        return [
            'handle' => 'fields',
            'field' => [
                'type' => 'grid',
                'display' => 'Fields',
                'mode' => 'table',
                'add_row' => 'Add field',
                'min_rows' => 1,
                'fields' => [
                    [
                        'handle' => 'field',
                        'field' => $options === null
                            ? ['type' => 'text', 'display' => 'Field', 'validate' => ['required']]
                            : ['type' => 'select', 'display' => 'Field', 'options' => $options, 'validate' => ['required']],
                    ],
                    [
                        'handle' => 'weight',
                        'field' => [
                            'type' => 'range',
                            'display' => 'Weight',
                            'min' => 0,
                            'max' => 100,
                            'step' => 1,
                            'default' => 1,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Every field across a collection's blueprints, since an entry can use any of them.
     */
    private static function fieldOptions(EntryCollection $collection): array
    {
        return $collection->entryBlueprints()
            ->flatMap(fn (FieldsBlueprint $blueprint) => $blueprint->fields()->all()
                ->mapWithKeys(fn ($field) => [$field->handle() => $field->display()]))
            ->sort()
            ->all();
    }
}
