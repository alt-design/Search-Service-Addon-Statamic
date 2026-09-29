<?php

namespace AltDesign\SearchService\Listeners;

use AltDesign\SearchService\Connection;
use AltDesign\SearchService\Jobs\SyncEntry;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;

class QueueEntrySync
{
    /**
     * A site with no service configured saves entries as normal rather than queueing jobs
     * that can only fail.
     *
     * The dispatch is rescued because a site on the sync queue connection runs the job here
     * and now: an unreachable search service would otherwise throw out of the save and stop
     * an editor publishing. The job still throws on a real queue connection, where throwing
     * is what earns it a retry, and `please search-service:sync` repairs whatever a reported
     * failure left behind.
     */
    public function handle(EntrySaved|EntryDeleted $event): void
    {
        if (! Connection::url() || ! Connection::key()) {
            return;
        }

        rescue(fn () => SyncEntry::dispatch($event->entry->id()));
    }
}
