<?php

use App\Models\FileJob;
use App\Models\ProcessedFile;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');

Schedule::call(function () {
    $expiredFiles = ProcessedFile::where('expires_at', '<', now())->get();

    foreach ($expiredFiles as $file) {
        if (Storage::disk('public')->exists($file->path)) {
            Storage::disk('public')->delete($file->path);
        }
        $file->delete();
    }
})->hourly();

Schedule::call(function () {
    // Delete expired jobs (older than 1 day)
    FileJob::expired()->delete();
})->daily();
