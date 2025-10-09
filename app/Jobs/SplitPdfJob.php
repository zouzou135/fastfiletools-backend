<?php

namespace App\Jobs;

use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Services\PdfService;
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

        $service = new PdfService();

        $result = $service->split(
            $this->data['pdf_path'],
            $this->data['pages'],
            $this->data['original_name'],
            function ($stage) use ($job) {
                // Callback from service to update stage
                $job->update(['progress_stage' => $stage]);
            }
        );

        if (!$result['success']) {
            $job->update([
                'status' => 'failed',
                'progress_stage' => 'error',
                'result' => ['message' => $result['message']]
            ]);
            return;
        }

        $job->update([
            'status' => 'completed',
            'progress_stage' => 'completed',
            'result' => ['split_pdfs' => $result['split_pdfs'], 'zip' => $result['zip'] ?? null]
        ]);
    }
}
