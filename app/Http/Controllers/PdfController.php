<?php

namespace App\Http\Controllers;

use App\Models\ProcessedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;

class PdfController extends Controller
{
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

        $pdf = $request->file('pdf');
        $tempPath = storage_path('app/temp/' . Str::random(20) . '.pdf');
        $pdf->move(dirname($tempPath), basename($tempPath));

        $fpdi = new Fpdi();
        $pageCount = $fpdi->setSourceFile($tempPath);

        $ranges = explode(',', $pageString);
        $splitPdfs = [];

        $originalName = pathinfo($pdf->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName     = Str::slug($originalName);

        foreach ($ranges as $range) {
            $range = trim($range);

            // Determine start and end
            if (strpos($range, '-') !== false) {
                [$start, $end] = array_map('intval', explode('-', $range));
            } else {
                $start = $end = (int)$range;
            }

            // Bounds check
            if ($start < 1 || $end > $pageCount || $start > $end) {
                unlink($tempPath);
                return response()->json([
                    'success' => false,
                    'message' => "Invalid range {$range}. This PDF has {$pageCount} pages."
                ], 422);
            }

            // Create one PDF for this range
            $fpdiRange = new Fpdi();
            $fpdiRange->setSourceFile($tempPath);

            for ($i = $start; $i <= $end; $i++) {
                $fpdiRange->AddPage();
                $template = $fpdiRange->importPage($i);
                $fpdiRange->useTemplate($template);
            }

            $filename = $safeName . '-pages-' . $start . '-' . $end . '-' . Str::random(8) . '.pdf';
            $content  = $fpdiRange->Output('', 'S');

            Storage::disk('public')->put('split/' . $filename, $content);

            $processedFile = ProcessedFile::create([
                'filename'   => $filename,
                'type'       => 'split',
                'path'       => 'split/' . $filename,
                'size'       => strlen($content),
                'expires_at' => now()->addHours(2),
            ]);

            $splitPdfs[] = [
                'range'        => $start === $end ? (string)$start : "{$start}-{$end}",
                'filename'     => $filename,
                'download_url' => route('files.download', ['type' => 'split', 'filename' => $filename]),
                'url'          => Storage::disk('public')->url($processedFile->path),
                'expires_at'   => now()->addHours(2)->toDateTimeString(),
            ];
        }

        unlink($tempPath);

        return response()->json([
            'success' => true,
            'split_pdfs' => $splitPdfs
        ]);
    }

    public function merge(Request $request)
    {
        $request->validate([
            'pdfs' => 'required|array',
            'pdfs.*' => 'mimes:pdf|max:50240'
        ]);

        $pdfs = $request->file('pdfs');
        $firstName   = pathinfo($pdfs[0]->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName    = Str::slug($firstName);
        $mergedFilename = $safeName . '-merged-' . Str::random(8) . '.pdf';


        $fpdi = new Fpdi();

        foreach ($pdfs as $pdf) {
            $tempPath = storage_path('app/temp/' . Str::random(20) . '.pdf');
            $pdf->move(dirname($tempPath), basename($tempPath));

            $pageCount = $fpdi->setSourceFile($tempPath);

            for ($i = 1; $i <= $pageCount; $i++) {
                $fpdi->AddPage();
                $template = $fpdi->importPage($i);
                $fpdi->useTemplate($template);
            }

            unlink($tempPath); // Clean up
        }

        $content = $fpdi->Output('', 'S');
        Storage::disk('public')->put('merged/' . $mergedFilename, $content);

        $processedFile = ProcessedFile::create([
            'filename'   => $mergedFilename,
            'type'       => 'merged',
            'path'       => 'merged/' . $mergedFilename,
            'size'       => strlen($content),
            'expires_at' => now()->addHours(2),
        ]);

        return response()->json([
            'success'      => true,
            'filename'     => $mergedFilename,
            'download_url' => route('files.download', ['type' => 'merged', 'filename' => $mergedFilename]),
            'url' => Storage::disk('public')->url($processedFile->path),
            'expires_at'   => now()->addHours(2)->toDateTimeString(),
        ]);
    }
}
