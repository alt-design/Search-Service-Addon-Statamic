<?php

namespace AltDesign\SearchService\Http\Controllers;

use AltDesign\SearchService\Fields;
use AltDesign\SearchService\Index;
use AltDesign\SearchService\Jobs\SyncCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Statamic\CP\PublishForm;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class FieldsController extends CpController
{
    public function edit()
    {
        $this->authorize('view search-service');

        $response = rescue(fn () => Http::searchService()->get('fields'), report: false);

        if (! $response?->successful()) {
            return redirect(cp_route('search-service.index'))
                ->withError('Could not load the field configs from the search service'.($response ? " (HTTP {$response->status()})" : '').'.');
        }

        $site = rescue(fn () => Http::searchService()->get('site'), report: false);

        return PublishForm::make(Fields::blueprint())
            ->title('Fields')
            ->icon('magnifying-glass')
            ->values(Fields::toValues($response->json('fields'), $site?->json('context') ?? []))
            ->readOnly(! User::current()->can('edit search-service fields'))
            ->submittingTo(cp_route('search-service.fields.update'));
    }

    public function update(Request $request)
    {
        $this->authorize('edit search-service fields');

        $values = PublishForm::make(Fields::blueprint())->submit($request->all());
        $fields = Fields::toFields($values);

        $response = rescue(fn () => Http::searchService()->put('fields', ['fields' => $fields]), report: false);

        if (! $response?->successful()) {
            throw ValidationException::withMessages([
                'collections' => $response?->json('message') ?? 'Could not reach the search service.',
            ]);
        }

        $this->describe(Fields::toContext($values));

        Index::forget();

        $this->reindex($fields);

        return ['saved' => true];
    }

    /**
     * Tell the service what each collection is, so it reads this site's words the way its
     * visitors mean them.
     *
     * Saved after the fields and never allowed to fail the save: a description makes
     * future classification better, so losing one is worth a line in the log rather than
     * throwing away a field config change that was the point of the save.
     *
     * @param  array<string, string>  $context
     */
    private function describe(array $context): void
    {
        $response = rescue(fn () => Http::searchService()->put('site', ['context' => $context]), report: false);

        if (! $response?->successful()) {
            Log::warning('Search service would not take the collection descriptions'.($response ? " (HTTP {$response->status()})" : '').'.');
        }
    }

    /**
     * Queue an index of every collection in the saved list. A field only stores values for
     * entries indexed after it was configured, so a save that adds a collection or adds a
     * field to one needs its entries sending again. Removing a collection needs no
     * counterpart: the service drops the stored values along with the field config.
     *
     * @param  list<array{field: string, weight: int}>  $fields
     */
    private function reindex(array $fields): void
    {
        collect($fields)
            ->map(fn (array $field): string => Str::before($field['field'], '.'))
            ->unique()
            ->each(fn (string $collection) => SyncCollection::dispatch($collection));
    }
}
