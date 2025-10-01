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
            'pdf' => 'required|mimes:pdf|max:50240', // 50MB max
            'pages' => 'required|string' // e.g., "1,3,5-8"
        ]);

        $pdf = $request->file('pdf');
        $pages = $this->parsePageRange($request->get('pages'));

        $tempPath = storage_path('app/temp/' . Str::random(20) . '.pdf');
        $pdf->move(dirname($tempPath), basename($tempPath));

        $splitPdfs = [];

        $originalName = pathinfo($pdf->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName     = Str::slug($originalName); // makes it URL-safe


        foreach ($pages as $pageNum) {
            $fpdi = new Fpdi();

            try {
                $pageCount = $fpdi->setSourceFile($tempPath);

                if ($pageNum <= $pageCount) {
                    $fpdi->AddPage();
                    $template = $fpdi->importPage($pageNum);
                    $fpdi->useTemplate($template);

                    $filename     = $safeName . '-page-' . $pageNum . '-' . Str::random(8) . '.pdf';
                    $content = $fpdi->Output('', 'S');

                    Storage::disk('public')->put('split/' . $filename, $content);

                    $processedFile = ProcessedFile::create([
                        'filename'   => $filename,
                        'type'       => 'split',
                        'path'       => 'split/' . $filename,
                        'size'       => strlen($content),
                        'expires_at' => now()->addHours(2),
                    ]);

                    $splitPdfs[] = [
                        'page'        => $pageNum,
                        'filename'    => $filename,
                        'download_url' => route('files.download', ['type' => 'split', 'filename' => $filename]),
                        'url' => Storage::disk('public')->url($processedFile->path),
                        'expires_at'  => now()->addHours(2)->toDateTimeString(),
                    ];
                }
            } catch (\Exception $e) {
                // Handle error
                continue;
            }
        }

        unlink($tempPath); // Clean up

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

    private function parsePageRange($pageString)
    {
        $pages = [];
        $parts = explode(',', $pageString);

        foreach ($parts as $part) {
            $part = trim($part);
            if (strpos($part, '-') !== false) {
                [$start, $end] = explode('-', $part);
                for ($i = (int)$start; $i <= (int)$end; $i++) {
                    $pages[] = $i;
                }
            } else {
                $pages[] = (int)$part;
            }
        }

        return array_unique($pages);
    }
}
