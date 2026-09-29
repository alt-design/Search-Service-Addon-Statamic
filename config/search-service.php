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

    /*
    |--------------------------------------------------------------------------
    | Search Rate Limit
    |--------------------------------------------------------------------------
    |
    | How many search requests a single visitor can make in a minute, keyed
    | by IP address. The service only ever sees this site's server, so it
    | cannot tell a busy site from one visitor hammering the search box;
    | this is what stops the latter.
    |
    */

    'search_rate_limit' => (int) env('SEARCH_SERVICE_SEARCH_RATE_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Search Cache
    |--------------------------------------------------------------------------
    |
    | How long, in seconds, an identical query, limit and offset is served
    | from cache rather than asked of the service again. Entries are always
    | resolved fresh, so this only saves the round trip, not what the site
    | shows for it. Set to 0 to disable caching.
    |
    */

    'search_cache_seconds' => (int) env('SEARCH_SERVICE_SEARCH_CACHE_SECONDS', 60),

];
