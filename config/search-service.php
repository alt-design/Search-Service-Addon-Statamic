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

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    |
    | How many documents go in one request to the index endpoint. Entries with
    | Bard or Replicator fields make large payloads, so this is well below the
    | service's own per-request limit.
    |
    */

    'batch_size' => (int) env('SEARCH_SERVICE_BATCH_SIZE', 50),

];
