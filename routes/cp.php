<?php

use AltDesign\SearchService\Http\Controllers\PipelineController;
use AltDesign\SearchService\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('search-service', StatusController::class)->name('search-service.index');
Route::get('search-service/pipeline', [PipelineController::class, 'edit'])->name('search-service.pipeline.edit');
Route::patch('search-service/pipeline', [PipelineController::class, 'update'])->name('search-service.pipeline.update');
