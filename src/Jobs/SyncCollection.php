<?php

namespace AltDesign\SearchService\Jobs;

use AltDesign\SearchService\Index;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Statamic\Facades\Collection;

/**
 * Index every published entry of one collection, which is what a collection needs when its
 * fields are first configured: the entries already exist, so nothing would otherwise send
 * them until the next full sync.
 */
class SyncCollection implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $collection) {}

    public function handle(): void
    {
        $collection = Collection::findByHandle($this->collection);

        if ($collection === null) {
            return;
        }

        Index::push($collection->queryEntries()->whereStatus('published')->lazy());
    }
}
