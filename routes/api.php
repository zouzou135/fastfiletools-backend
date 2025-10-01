<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\PdfController;
use Illuminate\Support\Facades\Route;

Route::prefix('image')->group(function () {
    Route::post('/compress', [ImageController::class, 'compress']);
    Route::post('/tune', [ImageController::class, 'tune']);
    Route::post('/convert-to-pdf', [ImageController::class, 'convertToPdf']);
});

Route::prefix('pdf')->group(function () {
    Route::post('/split', [PdfController::class, 'split']);
    Route::post('/merge', [PdfController::class, 'merge']);
});

Route::get('/download/{type}/{filename}', [FileController::class, 'download'])->name('files.download');;
