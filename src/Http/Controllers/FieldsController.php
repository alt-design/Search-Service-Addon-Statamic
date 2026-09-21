<?php

namespace AltDesign\SearchService\Http\Controllers;

use AltDesign\SearchService\Fields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

        return PublishForm::make(Fields::blueprint())
            ->title('Fields')
            ->icon('magnifying-glass')
            ->values(Fields::toValues($response->json('fields')))
            ->readOnly(! User::current()->can('edit search-service fields'))
            ->submittingTo(cp_route('search-service.fields.update'));
    }

    public function update(Request $request)
    {
        $this->authorize('edit search-service fields');

        $values = PublishForm::make(Fields::blueprint())->submit($request->all());

        $response = rescue(fn () => Http::searchService()->put('fields', ['fields' => Fields::toFields($values)]), report: false);

        if (! $response?->successful()) {
            throw ValidationException::withMessages([
                'collections' => $response?->json('message') ?? 'Could not reach the search service.',
            ]);
        }

        return ['saved' => true];
    }
}
