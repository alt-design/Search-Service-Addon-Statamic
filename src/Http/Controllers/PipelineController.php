<?php

namespace AltDesign\SearchService\Http\Controllers;

use AltDesign\SearchService\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Statamic\CP\PublishForm;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class PipelineController extends CpController
{
    public function edit()
    {
        $this->authorize('view search-service');

        $response = rescue(fn () => Http::searchService()->get('pipeline'), report: false);

        if (! $response?->successful()) {
            return redirect(cp_route('search-service.index'))
                ->withError('Could not load the pipeline from the search service'.($response ? " (HTTP {$response->status()})" : '').'.');
        }

        $steps = $response->json('steps');

        // Saving replaces the whole pipeline, so steps this version can't show would be deleted
        if ($unsupported = collect($steps)->pluck('transformer')->diff(Pipeline::transformers())->implode(', ')) {
            return redirect(cp_route('search-service.index'))
                ->withError("The pipeline contains steps this addon version can't edit ({$unsupported}). Update the addon.");
        }

        return PublishForm::make(Pipeline::blueprint())
            ->title('Pipeline')
            ->icon('magnifying-glass')
            ->values(Pipeline::toValues($steps))
            ->readOnly(! User::current()->can('edit search-service pipeline'))
            ->submittingTo(cp_route('search-service.pipeline.update'));
    }

    public function update(Request $request)
    {
        $this->authorize('edit search-service pipeline');

        $values = PublishForm::make(Pipeline::blueprint())->submit($request->all());

        $response = rescue(fn () => Http::searchService()->put('pipeline', ['steps' => Pipeline::toSteps($values)]), report: false);

        if (! $response?->successful()) {
            throw ValidationException::withMessages([
                'steps' => $response?->json('message') ?? 'Could not reach the search service.',
            ]);
        }

        return ['saved' => true];
    }
}
