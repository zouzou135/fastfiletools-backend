<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\PdfController;
use Illuminate\Support\Facades\Route;

Route::prefix('image')->group(function () {
    Route::post('/compress', [ImageController::class, 'compress']);
    Route::post('/tune', [ImageController::class, 'tune']);
    Route::post('/convert-to-pdf', [ImageController::class, 'convertToPdf']);
    Route::post('/convert-to-png', [PdfController::class, 'toPng']);
    Route::post('/convert-to-jpeg', [PdfController::class, 'toJpeg']);
});

Route::prefix('pdf')->group(function () {
    Route::post('/split', [PdfController::class, 'split']);
    Route::post('/merge', [PdfController::class, 'merge']);
    Route::post('/convert-to-img', [PdfController::class, 'pdfsToImages']);
});

Route::get('/download/{type}/{filename}', [FileController::class, 'download'])->name('files.download');

Route::get('/file-job/{id}', function ($id) {
    $job = \App\Models\FileJob::findOrFail($id);
    if ($job->status === 'failed') {
        return response()->json([
            'status' => $job->status,
            'progress_stage' => $job->progress_stage,
            'result' => $job->result,
        ], 422); // or 400/500 depending on semantics
    }

    return response()->json([
        'status' => $job->status,
        'progress_stage' => $job->progress_stage,
        'result' => $job->result,
    ]);
});
