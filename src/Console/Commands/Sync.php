<?php

namespace AltDesign\SearchService\Console\Commands;

use AltDesign\SearchService\Connection;
use AltDesign\SearchService\Index;
use Illuminate\Console\Command;
use Illuminate\Support\Collection as SupportCollection;
use RuntimeException;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;

class Sync extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:search-service:sync {--collection=* : Limit the sync to these collections}';

    protected $description = 'Push every published entry of the active collections to the search service';

    public function handle(): int
    {
        if (! Connection::url() || ! Connection::key()) {
            $this->components->error('Set the service URL and API key in the addon settings or .env');

            return self::FAILURE;
        }

        Index::forget();

        try {
            return $this->sync();
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function sync(): int
    {
        $active = Index::activeCollections();

        if ($active === []) {
            $this->components->warn('No collection has field configs on the search service, so there is nothing to index.');

            return self::SUCCESS;
        }

        $requested = $this->option('collection');
        $unknown = array_diff($requested, $active);

        if ($unknown !== []) {
            $this->components->error('Not active on the search service: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $pushed = collect();

        foreach ($requested ?: $active as $handle) {
            $collection = Collection::findByHandle($handle);

            if ($collection === null) {
                $this->components->warn("Configured on the search service but not a collection here: {$handle}");

                continue;
            }

            $references = Index::push($collection->queryEntries()->whereStatus('published')->lazy());

            $pushed = $pushed->concat($references);

            $this->components->twoColumnDetail($handle, count($references).' sent');
        }

        $requested === [] ? $this->prune($pushed) : $this->components->warn('Syncing named collections only, so nothing was pruned.');

        $this->components->info("{$pushed->count()} documents sent to the search service.");

        return self::SUCCESS;
    }

    /**
     * Remove documents the site no longer has. A full run sends every published entry of
     * every active collection, so anything else the service holds is stale: an entry
     * deleted while the service was unreachable, or a collection since deactivated.
     *
     * @param  SupportCollection<int, string>  $pushed
     */
    private function prune(SupportCollection $pushed): void
    {
        $stale = Index::references()->diff($pushed);

        $stale->each(fn (string $reference) => Index::delete($reference));

        $this->components->twoColumnDetail('Pruned', $stale->count().' stale');
    }
}
