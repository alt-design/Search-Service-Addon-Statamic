<?php

namespace AltDesign\SearchService;

use Illuminate\Support\Facades\Http;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $vite = [
        'input' => ['resources/js/addon.js'],
        'publicDirectory' => 'resources/dist',
    ];

    public function bootAddon()
    {
        // Pre-authenticated client for the search service API: Http::searchService()->get('search', [...])
        Http::macro('searchService', fn () => Http::baseUrl(rtrim((string) Connection::url(), '/').'/api')
            ->withToken((string) Connection::key())
            ->acceptJson()
            ->timeout(10));

        Permission::extend(fn () => Permission::register('view search-service', fn ($permission) => $permission
            ->label('View Search Service')
            ->children([Permission::make('edit search-service pipeline')->label('Edit Pipeline')])));

        Nav::extend(fn ($nav) => $nav->tools('Search Service')
            ->route('search-service.index')
            ->icon('magnifying-glass')
            ->can('view search-service')
            ->children([
                'Status' => cp_route('search-service.index'),
                'Pipeline' => cp_route('search-service.pipeline.edit'),
                'Test Pipeline' => cp_route('search-service.test'),
            ]));

        $this->registerSettingsBlueprint(function () {
            $instructions = fn (string $handle, string $env, string $text) => config("search-service.{$handle}")
                ? "Currently overridden by `{$env}` in .env."
                : "{$text} `{$env}` in .env takes priority when set.";

            return [
                'tabs' => ['main' => ['sections' => [['fields' => [
                    [
                        'handle' => 'url',
                        'field' => [
                            'type' => 'text',
                            'input_type' => 'url',
                            'display' => 'Service URL',
                            'instructions' => $instructions('url', 'SEARCH_SERVICE_URL', 'Root URL of the search service, without /api.'),
                            'validate' => ['nullable', 'url'],
                        ],
                    ],
                    [
                        'handle' => 'key',
                        'field' => [
                            'type' => 'text',
                            'input_type' => 'password',
                            'autocomplete' => 'new-password',
                            'display' => 'API Key',
                            'instructions' => $instructions('key', 'SEARCH_SERVICE_KEY', 'The site API key issued by the search service.'),
                        ],
                    ],
                ]]]]],
            ];
        });
    }
}
