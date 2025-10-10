<?php

namespace App\Http\Controllers;

use App\Models\ProcessedFile;
use File;
use Illuminate\Http\File as HttpFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use ZipArchive;

class ImageController extends Controller
{

    protected ImageManager $imageManager;

    public function __construct()
    {
        // Initialize once for all methods
        $this->imageManager = new ImageManager(new Driver());

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


    public function compress(Request $request)
    {
        $request->validate([
            'image'   => 'required|image|max:10240', // 10MB max
            'quality' => 'integer|between:10,100'
        ]);

        $image    = $request->file('image');
        $quality  = $request->get('quality', 80);
        $originalName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($originalName); // makes it URL-safe
        $extension = strtolower($image->getClientOriginalExtension());
        $filename = $safeName . '-' . Str::random(8) . '.' . $extension;;
        $path     = 'compressed/' . $filename;

        $img        = $this->imageManager->read($image);

        // Encode based on format
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
            case 'webp':
                $compressed = $img->encodeByExtension($extension, $quality);
                break;
            case 'png':
                $compressed = null;
                // $compressed = $img->encodeByExtension('png'); // quality ignored here
                break;
            default:
                try {
                    $compressed = $img->encodeByExtension($extension, $quality);
                } catch (\Exception $e) {
                    // fallback to default encoding if quality fails
                    $compressed = $img->encodeByExtension($extension);
                }
        }

        if ($compressed != null) {
            Storage::disk('public')->put($path, $compressed);
        } else {
            Storage::disk('public')->putFileAs('compressed', $image, $filename);
        }


        $fullPath = storage_path("app/public/{$path}");

        // Post-process with shell tools
        if (in_array($extension, ['jpg', 'jpeg'])) {
            exec("jpegoptim --strip-all --preserve --max={$quality} {$fullPath}");
        } elseif ($extension === 'png') {
            $minQuality = max(0, $quality - 20);
            $maxQuality = min(100, $quality);
            exec("pngquant --force --quality={$minQuality}-{$maxQuality} --output {$fullPath} {$fullPath}");
        }

        // Clearing the cache of the path because it was returning the size before running pngquant
        clearstatcache(true, $fullPath);
        $finalSize = filesize($fullPath);

        $processedFile = ProcessedFile::create([
            'filename'   => $filename,
            'type'       => 'compressed',
            'path'       => $path,
            'size'       => $finalSize,
            'expires_at' => now()->addHours(2),
        ]);

        return response()->json([
            'success'         => true,
            'filename'        => $processedFile->filename,
            'download_url'    => route('files.download', [
                'type'     => $processedFile->type,
                'filename' => $processedFile->filename,
            ]),
            'url'     => Storage::disk('public')->url($processedFile->path),
            'expires_at'      => $processedFile->expires_at->toDateTimeString(),
            'original_size'   => $image->getSize(),
            'compressed_size' => $processedFile->size,
        ]);
    }

    public function tune(Request $request)
    {
        $request->validate([
            'image'      => 'required|image|max:10240',
            'brightness' => 'integer|between:-100,100',
            'contrast'   => 'integer|between:-100,100',
            'saturation' => 'integer|between:-100,100'
        ]);

        $image    = $request->file('image');
        $originalName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($originalName); // makes it URL-safe
        $filename = $safeName . '-' . Str::random(8) . '.' . $image->getClientOriginalExtension();
        $path     = 'tuned/' . $filename;

        $img     = $this->imageManager->read($image);

        if ($request->has('brightness')) {
            $img->brightness($request->get('brightness'));
        }
        if ($request->has('contrast')) {
            $img->contrast($request->get('contrast'));
        }
        if ($request->has('saturation')) {
            // Intervention Image doesn’t support saturation directly
        }

        $tuned = $img->encodeByExtension($image->getClientOriginalExtension());
        Storage::disk('public')->put($path, $tuned);

        $processedFile = ProcessedFile::create([
            'filename'   => $filename,
            'type'       => 'tuned',
            'path'       => $path,
            'size'       => strlen($tuned),
            'expires_at' => now()->addHours(2),
        ]);

        return response()->json([
            'success'      => true,
            'filename'     => $processedFile->filename,
            'download_url' => route('files.download', [
                'type'     => $processedFile->type,
                'filename' => $processedFile->filename,
            ]),
            'url'     => Storage::disk('public')->url($processedFile->path),
            'expires_at'   => $processedFile->expires_at->toDateTimeString(),
        ]);
    }

    public function convertToPdf(Request $request)
    {
        $request->validate([
            'images'   => 'required|array',
            'images.*' => 'image|max:10240'
        ]);

        $images = $request->file('images');

        // Use the first image’s name as a base
        $originalName = pathinfo($images[0]->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName     = Str::slug($originalName);

        // Add a short random suffix for uniqueness
        $pdfFilename  = $safeName . '-' . Str::random(8) . '.pdf';
        $path         = 'pdfs/' . $pdfFilename;


        $pdf = new \TCPDF();

        foreach ($images as $image) {
            $extension = strtolower($image->getClientOriginalExtension());

            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                // Fallback: re-encode to jpg or reject
                $extension = 'jpg';
            }

            $img      = $this->imageManager->read($image);

            // Create a temp file path inside storage/app/temp
            $filename  = 'tmp_' . Str::random(12) . '.' . $extension;
            $tempPath  = storage_path('app/temp/' . $filename);

            // Save the image into that path
            $img->save($tempPath);

            $pdf->AddPage();
            $pdf->Image($tempPath, 10, 10, 190, 0, strtoupper($extension));

            unlink($tempPath);
        }

        $pdfContent = $pdf->Output('', 'S');
        Storage::disk('public')->put($path, $pdfContent);

        $processedFile = ProcessedFile::create([
            'filename'   => $pdfFilename,
            'type'       => 'pdf',
            'path'       => $path,
            'size'       => strlen($pdfContent),
            'expires_at' => now()->addHours(2),
        ]);

        return response()->json([
            'success'      => true,
            'filename'     => $processedFile->filename,
            'download_url' => route('files.download', [
                'type'     => $processedFile->type,
                'filename' => $processedFile->filename,
            ]),
            'url'     => Storage::disk('public')->url($processedFile->path),
            'expires_at'   => $processedFile->expires_at->toDateTimeString(),
        ]);
    }

    public function toPng(Request $request)
    {
        $request->validate([
            'images'   => 'required|array',
            'images.*' => 'image|max:10240',
        ]);

        $images = $request->file('images');

        // Use the first image’s name as a base
        $originalName = pathinfo($images[0]->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName     = Str::slug($originalName);

        $imageFiles = [];

        // Decide early: do we need a ZIP?
        $makeZip = count($images) > 1;
        $zip = null;
        $zipFilename = null;
        $zipPath = null;
        if ($makeZip) {
            $zipFilename = $safeName . '-img-png-' . Str::random(8) . '.zip';
            $zipPath = storage_path("app/temp/$zipFilename");
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE);
        }

        foreach ($images as $image) {
            $originalName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
            $safeName = Str::slug($originalName);
            $filename = $safeName . '-' . Str::random(8) . '.png';
            $path = 'img-png/' . $filename;

            $img = $this->imageManager->read($image)->encodeByExtension('png');
            Storage::disk('public')->put($path, $img);

            if ($makeZip) {
                $zip->addFromString($filename, $img);
            }

            $processedFile = ProcessedFile::create([
                'filename' => $filename,
                'type' => 'img-png',
                'path' => $path,
                'size' => Storage::disk('public')->size($path),
                'expires_at' => now()->addHours(2),
            ]);

            $imageFiles[] = [
                'filename' => $processedFile->filename,
                'url' => Storage::disk('public')->url($processedFile->path),
                'download_url' => route('files.download', [
                    'type' => $processedFile->type,
                    'filename' => $processedFile->filename,
                ]),
                'expires_at' => $processedFile->expires_at->toDateTimeString(),
            ];
        }

        $result = [
            'success' => true,
            'files' => $imageFiles,
        ];

        if ($makeZip) {
            $zip->close();

            $publicZipPath = "img-png/$zipFilename";
            Storage::disk('public')->putFileAs(
                'img-png',
                new HttpFile($zipPath),
                $zipFilename
            );

            $processedZip = ProcessedFile::create([
                'filename'   => $zipFilename,
                'type'       => 'img-png',
                'path'       => $publicZipPath,
                'size'       => Storage::disk('public')->size($publicZipPath),
                'expires_at' => now()->addHours(2),
            ]);

            $result['zip'] = [
                'filename'     => $zipFilename,
                'download_url' => route('files.download', ['type' => 'img-png', 'filename' => $zipFilename]),
                'url'          => Storage::disk('public')->url($processedZip->path),
                'expires_at'   => $processedZip->expires_at->toDateTimeString(),
            ];
        }

        return response()->json($result);
    }

    public function toJpeg(Request $request)
    {
        $request->validate([
            'images'   => 'required|array',
            'images.*' => 'image|max:10240',
            'quality'  => 'integer|between:10,100',
        ]);

        $quality = (int) $request->get('quality', 85);
        $imageFiles = [];

        $images = $request->file('images');

        // Use the first image’s name as a base
        $originalName = pathinfo($images[0]->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName     = Str::slug($originalName);

        // Decide early: do we need a ZIP?
        $makeZip = count($images) > 1;
        $zip = null;
        $zipFilename = null;
        $zipPath = null;
        if ($makeZip) {
            $zipFilename = $safeName . '-img-jpeg-' . Str::random(8) . '.zip';
            $zipPath = storage_path("app/temp/$zipFilename");
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE);
        }

        foreach ($request->file('images') as $image) {
            $originalName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
            $safeName = Str::slug($originalName);
            $filename = $safeName . '-' . Str::random(8) . '.jpg';
            $path = 'img-jpeg/' . $filename;

            $img = $this->imageManager->read($image)->encodeByExtension('jpg', $quality);
            Storage::disk('public')->put($path, $img);

            if ($makeZip) {
                $zip->addFromString($filename, $img);
            }

            $processedFile = ProcessedFile::create([
                'filename' => $filename,
                'type' => 'img-jpeg',
                'path' => $path,
                'size' => Storage::disk('public')->size($path),
                'expires_at' => now()->addHours(2),
            ]);

            // $abs = storage_path("app/public/{$path}");
            // // Optional post-process: jpegoptim for further reduction (if installed)
            // @exec("jpegoptim --strip-all --preserve --max={$quality} {$abs}");

            // clearstatcache(true, $abs);

            $imageFiles[] = [
                'filename' => $processedFile->filename,
                'url' => Storage::disk('public')->url($processedFile->path),
                'download_url' => route('files.download', [
                    'type' => $processedFile->type,
                    'filename' => $processedFile->filename,
                ]),
                'expires_at' => $processedFile->expires_at->toDateTimeString(),
            ];
        }

        $result = [
            'success' => true,
            'files' => $imageFiles,
        ];

        if ($makeZip) {
            $zip->close();

            $publicZipPath = "img-jpeg/$zipFilename";
            Storage::disk('public')->putFileAs(
                'img-jpeg',
                new HttpFile($zipPath),
                $zipFilename
            );

            $processedZip = ProcessedFile::create([
                'filename'   => $zipFilename,
                'type'       => 'img-jpeg',
                'path'       => $publicZipPath,
                'size'       => Storage::disk('public')->size($publicZipPath),
                'expires_at' => now()->addHours(2),
            ]);

            $result['zip'] = [
                'filename'     => $zipFilename,
                'download_url' => route('files.download', ['type' => 'img-jpeg', 'filename' => $zipFilename]),
                'url'          => Storage::disk('public')->url($processedZip->path),
                'expires_at'   => $processedZip->expires_at->toDateTimeString(),
            ];
        }

        return response()->json($result);
    }
}
