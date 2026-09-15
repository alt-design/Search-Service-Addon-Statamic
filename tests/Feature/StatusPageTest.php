<?php

use Illuminate\Support\Facades\Http;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;

uses(FakesRoles::class);

beforeEach(function () {
    config([
        'search-service.url' => 'https://search.test',
        'search-service.key' => '1|secret',
    ]);
});

it('shows the connection status to users with permission', function () {
    Http::fake(['*' => Http::response(['name' => 'Alt', 'base_url' => 'https://alt.test'])]);

    $this->actingAs(User::make()->id('admin')->email('admin@alt.test')->makeSuper())
        ->get(cp_route('search-service.index'))
        ->assertOk()
        ->assertSee('Authenticated as Alt (https://alt.test)');
});

it('hides the page from users without permission', function () {
    Http::fake();
    // CP access, but not 'view search-service', so the controller's own check is what's tested
    $this->setTestRoles(['editor' => ['access cp']]);

    $this->actingAs(User::make()->id('editor')->email('editor@alt.test')->assignRole('editor'))
        ->get(cp_route('search-service.index'))
        // The CP turns failed authorization into a redirect with a flashed error, not a 403
        ->assertRedirect()
        ->assertSessionHas('error', 'This action is unauthorized.');

    Http::assertNothingSent();
});
