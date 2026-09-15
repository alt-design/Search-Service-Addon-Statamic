<?php

namespace AltDesign\SearchService;

use Facades\Statamic\Fieldtypes\RowId;
use Illuminate\Support\Arr;
use Statamic\Facades\Blueprint;

/**
 * The pipeline editor: a replicator of steps, mapped to and from the search service's pipeline API (docs/api/pipeline.md).
 */
class Pipeline
{
    /**
     * Transformer handles this addon can edit.
     *
     * @return list<string>
     */
    public static function transformers(): array
    {
        return array_keys(static::sets());
    }

    public static function blueprint(): \Statamic\Fields\Blueprint
    {
        return Blueprint::make('search-service-pipeline')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [[
                'handle' => 'steps',
                'field' => [
                    'type' => 'replicator',
                    'display' => 'Steps',
                    'instructions' => 'Run in order, top to bottom. Changes only apply to documents indexed after saving.',
                    'sets' => static::sets(),
                ],
            ]]]]]],
        ]);
    }

    /**
     * API steps to editor values.
     *
     * @param  list<array{transformer: string, enabled: bool, config: array<string, mixed>}>  $steps
     */
    public static function toValues(array $steps): array
    {
        return ['steps' => collect($steps)->map(fn (array $step) => [
            'type' => $step['transformer'],
            'enabled' => $step['enabled'],
            ...$step['config'],
            ...isset($step['config']['weights']) ? [
                'weights' => collect($step['config']['weights'])->map(fn ($weight, $field) => compact('field', 'weight'))->values()->all(),
            ] : [],
        ])->all()];
    }

    /**
     * Processed editor values to API steps.
     *
     * @return list<array{transformer: string, enabled: bool, config: object}>
     */
    public static function toSteps(array $values): array
    {
        return collect($values['steps'] ?? [])->map(fn (array $set) => [
            'transformer' => $set['type'],
            'enabled' => $set['enabled'] ?? true,
            // Object so an empty config is sent as {} rather than []
            'config' => (object) collect(Arr::except($set, [RowId::handle(), 'type', 'enabled']))
                ->when(isset($set['weights']), fn ($config) => $config->put('weights', collect($set['weights'])->pluck('weight', 'field')->all()))
                ->reject(fn ($value) => $value === null || $value === [])
                ->all(),
        ])->values()->all();
    }

    private static function sets(): array
    {
        $fields = [
            'handle' => 'fields',
            'field' => ['type' => 'taggable', 'display' => 'Fields', 'instructions' => 'Leave blank to apply to all fields.'],
        ];

        return [
            'filter_configured_fields' => [
                'display' => 'Filter Configured Fields',
                'instructions' => 'Drops every field that has no field config on the search service.',
                'fields' => [],
            ],
            'trim' => [
                'display' => 'Trim',
                'instructions' => 'Trims surrounding whitespace.',
                'fields' => [$fields],
            ],
            'strip_html' => [
                'display' => 'Strip HTML',
                'instructions' => 'Removes HTML tags.',
                'fields' => [$fields],
            ],
            'decode_field' => [
                'display' => 'Decode Field',
                'instructions' => 'Turns structured values into plain text.',
                'fields' => [
                    ['handle' => 'format', 'field' => [
                        'type' => 'select',
                        'display' => 'Decode as',
                        'options' => ['json' => 'JSON decode', 'html' => 'Strip HTML tags', 'tiptap' => 'Tiptap decode (Bard)'],
                        'validate' => ['required'],
                    ]],
                    $fields,
                ],
            ],
            'stop_words' => [
                'display' => 'Stop Words',
                'instructions' => 'Removes words from field values.',
                'fields' => [
                    ['handle' => 'words', 'field' => [
                        'type' => 'taggable',
                        'display' => 'Stop words',
                        'instructions' => 'Matched whole-word and case-insensitively, so "cost" won\'t touch "Costco".',
                        'validate' => ['required'],
                    ]],
                    $fields,
                ],
            ],
            'synonym_inference' => [
                'display' => 'Synonym Inference',
                'instructions' => 'Appends LLM-generated synonyms for trigger words.',
                'fields' => [
                    ['handle' => 'trigger_words', 'field' => [
                        'type' => 'taggable',
                        'display' => 'Trigger words',
                        'instructions' => 'One LLM call per matched word (whole-word, case-insensitive), so keep this list small.',
                        'validate' => ['required', 'max:20'],
                    ]],
                    ['handle' => 'max_synonyms', 'field' => [
                        'type' => 'integer',
                        'display' => 'Max synonyms',
                        'default' => 5,
                        'validate' => ['required', 'integer', 'min:1', 'max:20'],
                    ]],
                    $fields,
                ],
            ],
            'adjust_weights' => [
                'display' => 'Adjust Weights',
                'instructions' => 'Overrides the configured weight for each field listed.',
                'fields' => [
                    ['handle' => 'weights', 'field' => [
                        'type' => 'grid',
                        'display' => 'Weights',
                        'mode' => 'table',
                        'min_rows' => 1,
                        'add_row' => 'Add field',
                        'fields' => [
                            ['handle' => 'field', 'field' => ['type' => 'text', 'display' => 'Field', 'validate' => ['required']]],
                            ['handle' => 'weight', 'field' => ['type' => 'integer', 'display' => 'Weight', 'validate' => ['required', 'integer', 'min:0']]],
                        ],
                    ]],
                ],
            ],
        ];
    }
}
