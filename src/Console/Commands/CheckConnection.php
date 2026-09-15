<?php

namespace AltDesign\SearchService\Console\Commands;

use AltDesign\SearchService\Connection;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

class CheckConnection extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:search-service:check';

    protected $description = 'Check the search service URL and API key are valid';

    public function handle(): int
    {
        ['ok' => $ok, 'message' => $message] = Connection::check();

        $ok ? $this->components->info($message) : $this->components->error($message);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
