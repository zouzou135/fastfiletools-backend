<?php

namespace App\Http\Controllers;

use App\Models\ProcessedFile;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function download($type, $filename)
    {
        $file = ProcessedFile::where('filename', $filename)
            ->where('type', $type)
            ->first();

        if (!$file || $file->isExpired()) {
            return response()->json(['error' => 'File not found or expired'], 404);
        }

        if (!Storage::disk('public')->exists($file->path)) {
            return response()->json(['error' => 'File missing'], 404);
        }

        $file->incrementDownload();

        return response()->download(Storage::disk('public')->path($file->path), $filename);
    }
}
