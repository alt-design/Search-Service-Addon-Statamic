<?php

use AltDesign\SearchService\Http\Controllers\AskController;
use AltDesign\SearchService\Http\Controllers\JourneyController;
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

// A GET for the same reason, though a turn is not quite a read: it reads a
// message and costs a call to a model. The service's own per-site limits are
// what bound that; this one only stops one visitor hammering the box.
Route::get('journey', JourneyController::class)
    ->middleware('throttle:'.(int) config('search-service.search_rate_limit').',1')
    ->name('search-service.journey');
