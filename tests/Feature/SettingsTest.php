<?php

use AltDesign\SearchService\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Addon;
use Statamic\Facades\User;

beforeEach(function () {
    config(['search-service.url' => null, 'search-service.key' => null]);

    Http::fake(['*' => Http::response(['name' => 'Alt', 'base_url' => 'https://alt.test'])]);
});

afterEach(fn () => Addon::get('alt-design/search-service')->settings()->delete());

it('saves the url and key through the settings page', function () {
    $this->actingAs(User::make()->id('admin')->email('admin@alt.test')->makeSuper());

    $this->get(cp_route('addons.settings.edit', 'search-service'))->assertOk();

    $this->patchJson(cp_route('addons.settings.update', 'search-service'), [
        'url' => 'https://settings.test',
        'key' => '2|from-settings',
    ])->assertOk()->assertJson(['saved' => true]);

    expect(Connection::check()['ok'])->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://settings.test/api/site'
        && $request->hasHeader('Authorization', 'Bearer 2|from-settings'));
});

it('prefers the env values over the settings page', function () {
    Addon::get('alt-design/search-service')->settings()
        ->set(['url' => 'https://settings.test', 'key' => '2|from-settings'])
        ->save();

    config(['search-service.url' => 'https://env.test', 'search-service.key' => '1|from-env']);

    Connection::check();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://env.test/api/site'
        && $request->hasHeader('Authorization', 'Bearer 1|from-env'));
});
