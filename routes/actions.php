<?php

use AltDesign\SearchService\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('search', SearchController::class)
    ->middleware('throttle:'.(int) config('search-service.search_rate_limit').',1')
    ->name('search-service.search');
