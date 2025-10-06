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

class SplitPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $data, public string $jobId) {}

    public function handle()
    {
        $job = FileJob::findOrFail($this->jobId);
        $job->update(['status' => 'processing', 'progress_stage' => 'uploaded']);

        $pdfPath = $this->data['pdf_path'];
        $pageString = $this->data['pages'];
        $originalName = pathinfo($this->data['original_name'], PATHINFO_FILENAME);
        $safeName = Str::slug($originalName);

        $normalizedPath = storage_path('app/temp/normalized-' . Str::random(8) . '.pdf');

        try {
            // Normalize with Ghostscript
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
                    'result' => ['message' => $process->getOutput()]
                ]);
                return;
            }

            $fpdi = new Fpdi();
            $pageCount = $fpdi->setSourceFile($normalizedPath);

            $ranges = explode(',', $pageString);
            $splitPdfs = [];

            $job->update(['progress_stage' => 'splitting']);

            foreach ($ranges as $range) {
                $range = trim($range);
                [$start, $end] = strpos($range, '-') !== false
                    ? array_map('intval', explode('-', $range))
                    : [(int)$range, (int)$range];

                // Bounds check
                if ($start < 1 || $end > $pageCount || $start > $end) {
                    $job->update([
                        'status' => 'failed',
                        'progress_stage' => 'validation_failed',
                        'result' => ['message' => "Invalid range {$range}. This PDF has {$pageCount} pages."]
                    ]);
                    return;
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
            }

            $job->update([
                'status' => 'completed',
                'progress_stage' => 'completed',
                'result' => ['split_pdfs' => $splitPdfs]
            ]);
        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'progress_stage' => 'error',
                'result' => ['message' => $e->getMessage()]
            ]);
            throw $e;
        } finally {
            if (file_exists($normalizedPath)) {
                unlink($normalizedPath);
            }
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }
}
