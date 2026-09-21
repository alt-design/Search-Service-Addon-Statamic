<?php

use AltDesign\SearchService\Http\Controllers\PipelineController;
use AltDesign\SearchService\Http\Controllers\StatusController;
use AltDesign\SearchService\Http\Controllers\TestPipelineController;
use Illuminate\Support\Facades\Route;

Route::get('search-service', StatusController::class)->name('search-service.index');
Route::get('search-service/pipeline', [PipelineController::class, 'edit'])->name('search-service.pipeline.edit');
Route::patch('search-service/pipeline', [PipelineController::class, 'update'])->name('search-service.pipeline.update');
Route::get('search-service/test', [TestPipelineController::class, 'show'])->name('search-service.test');
Route::get('search-service/test/document', [TestPipelineController::class, 'document'])->name('search-service.test.document');
Route::post('search-service/test/run', [TestPipelineController::class, 'run'])->name('search-service.test.run');
