<?php

namespace App\Http\Controllers;

use App\Jobs\MergePdfJob;
use App\Jobs\SplitPdfJob;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mostafaznv\PdfOptimizer\Enums\PdfSettings;
use Mostafaznv\PdfOptimizer\Laravel\Facade\PdfOptimizer;
use setasign\Fpdi\Tcpdf\Fpdi;
use Symfony\Component\Process\Process;

class PdfController extends Controller
{

    public function __construct()
    {
        // Only need to ensure temp directory exists
        // Storage::put() handles everything else automatically
        $this->ensureTempDirectory();
    }

    private function ensureTempDirectory(): void
    {
        $tempPath = storage_path('app/temp');

        if (!File::exists($tempPath)) {
            File::makeDirectory($tempPath, 0755, true);
        }
    }

    public function split(Request $request)
    {
        $request->validate([
            'pdf' => 'required|mimes:pdf|max:50240',
            'pages' => 'required|string'
        ]);

        $pageString = $request->get('pages');

        // Validate format first (using the stricter regex we discussed)
        if (!preg_match('/^\s*\d+(\s*-\s*\d+)?(\s*,\s*\d+(\s*-\s*\d+)?)*\s*$/', $pageString)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid page range format. Use numbers and ranges like "1,3,5-8".'
            ], 422);
        }

        $path = $request->file('pdf')->store('', 'temp');
        $absolutePath = Storage::disk('temp')->path($path);

        // Create job record
        $job = FileJob::create([
            'type' => 'pdf_split',
            'status' => 'pending',
            'progress_stage' => 'queued',
        ]);

        // Dispatch background job
        SplitPdfJob::dispatch([
            'pdf_path' => $absolutePath,
            'pages' => $pageString,
            'original_name' => $request->file('pdf')->getClientOriginalName(),
        ], $job->id);

        return response()->json(['job_id' => $job->id]);
    }

    public function merge(Request $request)
    {
        $request->validate([
            'pdfs' => 'required|array',
            'pdfs.*' => 'mimes:pdf|max:50240'
        ]);

        // Store all uploaded PDFs in temp
        $pdfPaths = array_map(
            fn($pdf) => Storage::disk('temp')->path($pdf->store('', 'temp')),
            $request->file('pdfs')
        );

        // Create job record
        $job = FileJob::create([
            'type' => 'pdf_merge',
            'status' => 'pending',
            'progress_stage' => 'queued',
        ]);

        // Dispatch background job
        MergePdfJob::dispatch([
            'pdf_paths' => $pdfPaths,
            'original_name' => $request->file('pdfs')[0]->getClientOriginalName(),
        ], $job->id);

        return response()->json(['job_id' => $job->id]);
    }

    public function normalizePdfWithGhostscript(string $inputPath, string $outputPath): Process
    {
        $process = new Process([
            'gs',
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/screen',
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            "-sOutputFile=$outputPath",
            $inputPath
        ]);

        $process->run();

        return $process;
    }
}
