<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('merges the addon config', function () {
    expect(config()->has('search-service.url'))->toBeTrue()
        ->and(config()->has('search-service.key'))->toBeTrue();
});

describe('statamic:search-service:check', function () {
    beforeEach(function () {
        config([
            'search-service.url' => 'https://search.test/',
            'search-service.key' => '1|secret',
        ]);
    });

    it('authenticates with the site api key', function () {
        Http::fake([
            'search.test/api/site' => Http::response(['name' => 'Alt', 'base_url' => 'https://alt.test']),
        ]);

        $this->artisan('statamic:search-service:check')
            ->expectsOutputToContain('Authenticated as Alt (https://alt.test)')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://search.test/api/site'
            && $request->hasHeader('Authorization', 'Bearer 1|secret'));
    });

    it('fails when the api key is rejected', function () {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->artisan('statamic:search-service:check')
            ->expectsOutputToContain('API key rejected')
            ->assertFailed();
    });

    it('fails when the url is not the search service', function () {
        Http::fake(['*' => Http::response('<html></html>')]);

        $this->artisan('statamic:search-service:check')
            ->expectsOutputToContain('Unexpected response')
            ->assertFailed();
    });

    it('fails without sending a request when not configured', function () {
        config(['search-service.key' => null]);
        Http::fake();

        $this->artisan('statamic:search-service:check')->assertFailed();

        Http::assertNothingSent();
    });
});
