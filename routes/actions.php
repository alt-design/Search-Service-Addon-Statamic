<?php

use AltDesign\SearchService\Http\Controllers\AskController;
use AltDesign\SearchService\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('search', SearchController::class)
    ->middleware('throttle:'.(int) config('search-service.search_rate_limit').',1')
    ->name('search-service.search');

// A read, so a GET: an action route runs CSRF verification, and a POST from
// plain JavaScript has no token to give it.
Route::get('ask', AskController::class)
    ->middleware('throttle:'.(int) config('search-service.search_rate_limit').',1')
    ->name('search-service.ask');
