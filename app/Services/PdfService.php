<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use App\Models\ProcessedFile;
use File;
use Illuminate\Http\File as HttpFile;
use setasign\Fpdi\Tcpdf\Fpdi;
use ZipArchive;

class PdfService
{
    public function split(string $pdfPath, string $pageString, string $originalName, ?callable $progressCallback = null): array
    {

        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $normalizedPath = storage_path('app/temp/normalized-' . Str::random(8) . '.pdf');

        try {
            // Normalize with Ghostscript
            if ($progressCallback !== null) {
                $progressCallback('normalizing');
            }
            $process = new Process([
                'gs',
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                '-dPDFSETTINGS=/screen',
                '-dNOPAUSE',
                '-dQUIET',
                '-dBATCH',
                "-sOutputFile=$normalizedPath",
                $pdfPath
            ]);
            $process->run();

            if (!$process->isSuccessful() || !file_exists($normalizedPath)) {
                return [
                    'success' => false,
                    'message' => 'Normalization failed: ' . $process->getErrorOutput()
                ];
            }

            $fpdi = new Fpdi();
            $pageCount = $fpdi->setSourceFile($normalizedPath);

            $ranges = explode(',', $pageString);
            $splitPdfs = [];

            if ($progressCallback !== null) {
                $progressCallback('splitting');
            }

            // Decide early: do we need a ZIP?
            $makeZip = count($ranges) > 1;
            $zip = null;
            $zipFilename = null;
            $zipPath = null;

            if ($makeZip) {
                // Prepare ZIP archive
                $zipFilename = $safeName . '-split-' . Str::random(8) . '.zip';
                $zipPath = storage_path("app/temp/$zipFilename");
                $zip = new ZipArchive();
                $zip->open($zipPath, ZipArchive::CREATE);
            }

            foreach ($ranges as $range) {
                $range = trim($range);
                [$start, $end] = strpos($range, '-') !== false
                    ? array_map('intval', explode('-', $range))
                    : [(int)$range, (int)$range];

                // Bounds check
                if ($start < 1 || $end > $pageCount || $start > $end) {
                    return [
                        'success' => false,
                        'message' => "Invalid range {$range}. This PDF has {$pageCount} pages."
                    ];
                }

                $fpdiRange = new Fpdi();
                $fpdiRange->setSourceFile($normalizedPath);

                for ($i = $start; $i <= $end; $i++) {
                    $fpdiRange->AddPage();
                    $template = $fpdiRange->importPage($i);
                    $fpdiRange->useTemplate($template);
                }

                $filename = $safeName . "-pages-$start-$end-" . Str::random(8) . '.pdf';
                $content  = $fpdiRange->Output('', 'S');

                Storage::disk('public')->put("split/$filename", $content);

                $processedFile = ProcessedFile::create([
                    'filename'   => $filename,
                    'type'       => 'split',
                    'path'       => "split/$filename",
                    'size'       => strlen($content),
                    'expires_at' => now()->addHours(2),
                ]);

                $splitPdfs[] = [
                    'range'        => $start === $end ? (string)$start : "$start-$end",
                    'filename'     => $filename,
                    'download_url' => route('files.download', ['type' => 'split', 'filename' => $filename]),
                    'url'          => Storage::disk('public')->url($processedFile->path),
                    'expires_at'   => $processedFile->expires_at->toDateTimeString(),
                ];

                // Only add to ZIP if we decided to make one
                if ($makeZip) {
                    $zip->addFromString($filename, $content);
                }
            }

            $result = [
                'success' => true,
                'split_pdfs' => $splitPdfs,
            ];

            if ($makeZip) {
                // Finalize ZIP
                if ($progressCallback != null) {
                    $progressCallback('zipping');
                }
                $zip->close();

                $publicZipPath = "split/$zipFilename";
                Storage::disk('public')->putFileAs(
                    'split',
                    new HttpFile($zipPath),
                    $zipFilename
                );

                $processedZip = ProcessedFile::create([
                    'filename'   => $zipFilename,
                    'type'       => 'split',
                    'path'       => $publicZipPath,
                    'size'       => Storage::disk('public')->size($publicZipPath),
                    'expires_at' => now()->addHours(2),
                ]);

                @unlink($zipPath);

                $result['zip'] = [
                    'filename'     => $zipFilename,
                    'download_url' => route('files.download', ['type' => 'split', 'filename' => $zipFilename]),
                    'url'          => Storage::disk('public')->url($processedZip->path),
                    'expires_at'   => $processedZip->expires_at->toDateTimeString(),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        } finally {
            if (file_exists($normalizedPath)) {
                unlink($normalizedPath);
            }
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    public function merge(array $pdfPaths, string $originalName, ?callable $progressCallback = null): array
    {

        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $mergedFilename = $safeName . '-merged-' . Str::random(8) . '.pdf';

        $fpdi = new Fpdi();
        $normalizedFiles = [];

        try {
            foreach ($pdfPaths as $pdfPath) {
                $normalizedPath = storage_path('app/temp/normalized-' . Str::random(8) . '.pdf');
                $normalizedFiles[] = $normalizedPath;

                if ($progressCallback !== null) {
                    $progressCallback('normalizing');
                }

                $process = new Process([
                    'gs',
                    '-sDEVICE=pdfwrite',
                    '-dCompatibilityLevel=1.4',
                    '-dPDFSETTINGS=/screen',
                    '-dNOPAUSE',
                    '-dQUIET',
                    '-dBATCH',
                    "-sOutputFile=$normalizedPath",
                    $pdfPath
                ]);
                $process->run();

                if (!$process->isSuccessful() || !file_exists($normalizedPath)) {
                    return [
                        'success' => false,
                        'message' => 'Normalization failed: ' . $process->getErrorOutput()
                    ];
                }

                if ($progressCallback !== null) {
                    $progressCallback('merging');
                }

                $pageCount = $fpdi->setSourceFile($normalizedPath);
                for ($i = 1; $i <= $pageCount; $i++) {
                    $fpdi->AddPage();
                    $template = $fpdi->importPage($i);
                    $fpdi->useTemplate($template);
                }
            }

            $content = $fpdi->Output('', 'S');
            Storage::disk('public')->put("merged/$mergedFilename", $content);

            $processedFile = ProcessedFile::create([
                'filename'   => $mergedFilename,
                'type'       => 'merged',
                'path'       => "merged/$mergedFilename",
                'size'       => strlen($content),
                'expires_at' => now()->addHours(2),
            ]);

            return [
                'success' => true,
                'filename'     => $mergedFilename,
                'download_url' => route('files.download', ['type' => 'merged', 'filename' => $mergedFilename]),
                'url'          => Storage::disk('public')->url($processedFile->path),
                'expires_at'   => $processedFile->expires_at->toDateTimeString(),

            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => ['message' => $e->getMessage()]
            ];
            throw $e;
        } finally {
            // Cleanup normalized and original temp files
            foreach ($normalizedFiles as $file) {
                if (file_exists($file)) unlink($file);
            }
            foreach ($pdfPaths as $file) {
                if (file_exists($file)) unlink($file);
            }
        }
    }
}
