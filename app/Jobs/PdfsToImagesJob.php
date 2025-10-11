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

class PdfsToImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $data, public string $jobId) {}

    public function handle()
    {
        $job = FileJob::findOrFail($this->jobId);
        $job->update(['status' => 'processing', 'progress_stage' => 'uploaded']);

        $service = new PdfService();

        $result = $service->pdfsToImages(
            $this->data['pdfPaths'],
            $this->data['original_name'],
            $this->data['dpi'] ?? 150, // default to 150 if not set
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
            'result' => $result
        ]);
    }
}
