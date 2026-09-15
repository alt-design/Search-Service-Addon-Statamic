<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service URL
    |--------------------------------------------------------------------------
    |
    | Root URL of the search service, without the /api suffix.
    |
    */

    'url' => env('SEARCH_SERVICE_URL'),

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | The site API key issued by the search service. Keep this in .env, it is
    | shown once when the site is created.
    |
    */

    'key' => env('SEARCH_SERVICE_KEY'),

];
