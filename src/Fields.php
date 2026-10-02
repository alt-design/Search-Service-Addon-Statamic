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

    /**
     * Field types worth indexing, being the ones holding words rather than structure.
     */
    private const INDEXABLE_TYPES = ['text', 'textarea', 'markdown', 'bard', 'taggable'];

    /**
     * Weights by handle, for the handles that conventionally mean something. A field not
     * listed here starts at 1, which is the weight a body of text deserves next to a
     * title.
     *
     * @var array<string, int>
     */
    private const SUGGESTED_WEIGHTS = [
        'title' => 50,
        'standfirst' => 10,
        'strapline' => 10,
        'intro' => 10,
        'summary' => 10,
        'excerpt' => 10,
        'description' => 10,
        'tags' => 6,
    ];

    public static function blueprint(): FieldsBlueprint
    {
        return Blueprint::make('search-service-fields')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [[
                'handle' => 'collections',
                'field' => [
                    'type' => 'replicator',
                    'display' => 'Collections',
                    'instructions' => 'Only the fields listed here are indexed, and a field weighted higher ranks its matches higher. Toggling a field, or a whole collection, off pauses it instantly and can be undone just as fast, with nothing to reindex. Removing a field or a collection is different: it deletes the values already stored for it on every entry, and only a full reindex brings them back.',
                    'collapse' => 'accordion',
                    'button_label' => 'Add collection',
                    'previews' => false,
                    'sets' => static::sets(),
                ],
            ]]]]]],
        ]);
    }

    /**
     * API field list to editor values, grouped into a panel per collection. A field the
     * service sends with no enabled key predates the flag and counts as enabled.
     *
     * @param  list<array{field: string, weight: int, enabled?: bool}>  $fields
     * @param  array<string, string>  $context  what the service already holds, keyed by collection
     */
    public static function toValues(array $fields, array $context = []): array
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
                'context' => $context[$set] ?? null,
                'fields' => $group->map(fn (array $field) => [
                    'field' => $set === static::ORPHANED ? $field['field'] : Str::after($field['field'], '.'),
                    'weight' => $field['weight'],
                    'enabled' => $field['enabled'] ?? true,
                ])->values()->all(),
            ])
            ->values()
            ->all()];
    }

    /**
     * What each collection says it is, keyed by collection, for the service's site API.
     *
     * The service treats the keys as opaque, so this is the same convention the field
     * names already use: the collection is this addon's grouping, not a service one.
     *
     * @return array<string, string>
     */
    public static function toContext(array $values): array
    {
        return collect($values['collections'] ?? [])
            ->filter(fn (array $set): bool => $set['type'] !== static::ORPHANED)
            ->mapWithKeys(fn (array $set): array => [$set['type'] => trim((string) ($set['context'] ?? ''))])
            ->reject(fn (string $context): bool => $context === '')
            ->all();
    }

    /**
     * Processed editor values to an API field list. Every field configured here is sent to
     * the service regardless of its toggle, since disabling one only pauses it: the service
     * keeps indexing it but leaves it out of search until it is switched back on. A disabled
     * panel overrides its rows, pausing all of them no matter how each is set individually.
     *
     * @return list<array{field: string, weight: int, enabled: bool}>
     */
    public static function toFields(array $values): array
    {
        return collect($values['collections'] ?? [])
            ->flatMap(function (array $set) {
                $panelEnabled = $set['enabled'] ?? true;

                return collect($set['fields'] ?? [])->map(fn (array $row) => [
                    'field' => $set['type'] === static::ORPHANED ? $row['field'] : "{$set['type']}.{$row['field']}",
                    'weight' => (int) $row['weight'],
                    'enabled' => $panelEnabled && ($row['enabled'] ?? true),
                ]);
            })
            ->values()
            ->all();
    }

    private static function sets(): array
    {
        $collections = Collection::all()->mapWithKeys(fn (EntryCollection $collection) => [
            $collection->handle() => [
                'display' => $collection->title(),
                'fields' => [static::context(), static::grid(static::fieldOptions($collection), static::suggestedRows($collection))],
            ],
        ])->all();

        return [
            ...$collections,
            static::ORPHANED => [
                'display' => 'Other fields',
                'instructions' => 'Fields configured on the search service that belong to no collection here, usually because the collection was deleted. Remove one to stop it being indexed.',
                'fields' => [static::grid(null, [])],
            ],
        ];
    }

    /**
     * What this collection is, in plain words, passed to the service and used when it
     * works out what the words in these entries mean.
     *
     * A word carries no single meaning on its own. Told nothing, a search for somewhere
     * to sit reads "sit" as a kind of thing rather than as a chair, and "charcoal"
     * becomes its own colour rather than a black. Saying what the collection is fixes
     * both, and it is worth the minute it takes.
     */
    private static function context(): array
    {
        return [
            'handle' => 'context',
            'field' => [
                'type' => 'textarea',
                'display' => 'What this is',
                'rows' => 2,
                'character_limit' => 500,
                'instructions' => 'One or two sentences on what these entries are and how people ask for them, for example: "An online furniture shop selling sofas, chairs and beds. Shoppers describe colours loosely, by naming something that colour." Left blank, the service reads every word cold. Changing this does not relabel words already done.',
            ],
        ];
    }

    /**
     * Null options means the field name is typed rather than picked, which is the only
     * option when there is no blueprint to read it from.
     *
     * The default rows are what a collection arrives with when it is added, so onboarding
     * one is a case of adjusting a sensible list rather than building it from nothing.
     *
     * @param  array<int, array{field: string, weight: int, enabled: bool}>  $rows
     */
    private static function grid(?array $options, array $rows): array
    {
        return [
            'handle' => 'fields',
            'field' => [
                'type' => 'grid',
                'display' => 'Fields',
                'mode' => 'table',
                'add_row' => 'Add field',
                'min_rows' => 1,
                'default' => $rows,
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
                    [
                        'handle' => 'enabled',
                        'field' => [
                            'type' => 'toggle',
                            'display' => 'Enabled',
                            'default' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The fields a collection is worth indexing, with a weight taken from what the handle
     * usually means. Only field types holding prose or keywords are included: an asset, a
     * toggle or a date has nothing a visitor would search for.
     *
     * @return array<int, array{field: string, weight: int, enabled: bool}>
     */
    private static function suggestedRows(EntryCollection $collection): array
    {
        return $collection->entryBlueprints()
            ->flatMap(fn (FieldsBlueprint $blueprint) => $blueprint->fields()->all())
            ->filter(fn ($field): bool => in_array($field->type(), static::INDEXABLE_TYPES, true))
            ->map(fn ($field): array => [
                'field' => $field->handle(),
                'weight' => static::SUGGESTED_WEIGHTS[$field->handle()] ?? 1,
                'enabled' => true,
            ])
            ->values()
            ->all();
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
