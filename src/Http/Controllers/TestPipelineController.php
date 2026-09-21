<?php

namespace AltDesign\SearchService\Http\Controllers;

use AltDesign\SearchService\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

class TestPipelineController extends CpController
{
    public function show()
    {
        $this->authorize('view search-service');

        // Entry picking is the CP's own entries fieldtype
        $blueprint = Blueprint::make('search-service-test')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [[
                'handle' => 'entry',
                'field' => ['type' => 'entries', 'display' => 'Entry', 'max_items' => 1, 'create' => false],
            ]]]]]],
        ]);

        $fields = $blueprint->fields()->preProcess();

        return Inertia::render('search-service::TestPipeline', [
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
            'documentUrl' => cp_route('search-service.test.document'),
            'runUrl' => cp_route('search-service.test.run'),
        ]);
    }

    /**
     * The document an entry sends to the search service.
     */
    public function document(Request $request)
    {
        $this->authorize('view search-service');

        return Document::fromEntry($this->entry($request));
    }

    /**
     * Run an entry's document through the site's saved pipeline. Nothing is stored.
     */
    public function run(Request $request)
    {
        $this->authorize('view search-service');

        $response = rescue(fn () => Http::searchService()->post('evaluate', Document::fromEntry($this->entry($request))), report: false);

        if (! $response?->successful()) {
            return ['error' => $response?->json('message') ?? 'Could not reach the search service.'];
        }

        return $response->json();
    }

    private function entry(Request $request): EntryContract
    {
        return Entry::find((string) $request->input('entry')) ?? abort(404);
    }
}
