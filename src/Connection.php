<?php

namespace AltDesign\SearchService;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Addon;

class Connection
{
    /**
     * The service URL. SEARCH_SERVICE_URL takes priority over the addon settings page.
     */
    public static function url(): ?string
    {
        return config('search-service.url') ?: static::setting('url');
    }

    /**
     * The site API key. SEARCH_SERVICE_KEY takes priority over the addon settings page.
     */
    public static function key(): ?string
    {
        return config('search-service.key') ?: static::setting('key');
    }

    /**
     * Read from the addon settings, stored by Statamic's settings repository (file, or database via the eloquent driver).
     */
    private static function setting(string $handle): ?string
    {
        return Addon::get('alt-design/search-service')->setting($handle) ?: null;
    }

    /**
     * Check the configured URL and API key against the search service.
     *
     * @return array{ok: bool, message: string}
     */
    public static function check(): array
    {
        if (! static::url() || ! static::key()) {
            return ['ok' => false, 'message' => 'Set the service URL and API key in the addon settings or .env'];
        }

        try {
            $response = Http::searchService()->get('site');
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => "Could not reach the search service: {$e->getMessage()}"];
        }

        if ($response->unauthorized()) {
            return ['ok' => false, 'message' => 'API key rejected by the search service'];
        }

        // A wrong URL can still return 200 (e.g. a front end), so require the site payload
        if (! $response->successful() || ! $response->json('name')) {
            return ['ok' => false, 'message' => "Unexpected response from the search service (HTTP {$response->status()})"];
        }

        return ['ok' => true, 'message' => "Authenticated as {$response->json('name')} ({$response->json('base_url')})"];
    }
}
