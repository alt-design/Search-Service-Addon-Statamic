<?php

use AltDesign\SearchService\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('search', SearchController::class)->name('search-service.search');
