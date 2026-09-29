<?php

namespace AltDesign\SearchService\Jobs;

use AltDesign\SearchService\Index;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Statamic\Facades\Entry;

/**
 * Bring one entry's document in the index up to date with the site.
 *
 * The entry is resolved when the job runs rather than carried in the payload, so one job
 * covers saving, unpublishing, deleting, and a collection losing its field configs:
 * anything the site will not serve is removed from the index.
 */
class SyncEntry implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $reference) {}

    public function handle(): void
    {
        $entry = Entry::find($this->reference);

        if ($entry === null || $entry->status() !== 'published' || ! Index::isActive($entry->collectionHandle())) {
            Index::delete($this->reference);

            return;
        }

        Index::push([$entry]);
    }
}
