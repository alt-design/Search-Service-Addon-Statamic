<?php

namespace AltDesign\SearchService\Http\Controllers;

use AltDesign\SearchService\Connection;
use Statamic\Facades\Addon;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class StatusController extends CpController
{
    public function __invoke()
    {
        $this->authorize('view search-service');

        $addon = Addon::get('alt-design/search-service');

        return view('search-service::status', [
            'url' => Connection::url(),
            'settingsUrl' => User::current()->can('editSettings', $addon) ? $addon->settingsUrl() : null,
            ...Connection::check(),
        ]);
    }
}
