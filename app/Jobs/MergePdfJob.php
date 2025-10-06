<?php

namespace App\Jobs;

use App\Models\FileJob;
use App\Models\ProcessedFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;
use Symfony\Component\Process\Process;

class MergePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $data, public string $jobId) {}

    public function handle()
    {
        $job = FileJob::findOrFail($this->jobId);
        $job->update(['status' => 'processing', 'progress_stage' => 'uploaded']);

        $pdfPaths = $this->data['pdf_paths'];
        $originalName = pathinfo($this->data['original_name'], PATHINFO_FILENAME);
        $safeName = Str::slug($originalName);
        $mergedFilename = $safeName . '-merged-' . Str::random(8) . '.pdf';

        $fpdi = new Fpdi();
        $normalizedFiles = [];

        try {
            foreach ($pdfPaths as $pdfPath) {
                $normalizedPath = storage_path('app/temp/normalized-' . Str::random(8) . '.pdf');
                $normalizedFiles[] = $normalizedPath;

                $job->update(['progress_stage' => 'normalizing']);

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
                    $job->update([
                        'status' => 'failed',
                        'progress_stage' => 'error',
                        'result' => ['message' => 'Normalization failed: ' . $process->getErrorOutput()]
                    ]);
                    return;
                }

                $job->update(['progress_stage' => 'merging']);

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

            $job->update([
                'status' => 'completed',
                'progress_stage' => 'completed',
                'result' => [
                    'filename'     => $mergedFilename,
                    'download_url' => route('files.download', ['type' => 'merged', 'filename' => $mergedFilename]),
                    'url'          => Storage::disk('public')->url($processedFile->path),
                    'expires_at'   => $processedFile->expires_at->toDateTimeString(),
                ]
            ]);
        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'progress_stage' => 'error',
                'result' => ['message' => $e->getMessage()]
            ]);
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
