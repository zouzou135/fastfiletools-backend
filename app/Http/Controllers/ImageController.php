<?php

namespace App\Http\Controllers;

use App\Models\ProcessedFile;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImageController extends Controller
{

    protected ImageManager $imageManager;

    public function __construct()
    {
        // Initialize once for all methods
        $this->imageManager = new ImageManager(new Driver());
    }

    private function createTempFile(string $extension = 'jpg'): string
    {
        return tempnam(sys_get_temp_dir(), 'tmp_') . '.' . $extension;
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
        $filename = $safeName . '-' . Str::random(8) . '.' . $image->getClientOriginalExtension();
        $path     = 'compressed/' . $filename;

        $img        = $this->imageManager->read($image);
        $compressed = $img->encodeByExtension($image->getClientOriginalExtension(), $quality);

        Storage::disk('public')->put($path, $compressed);

        $processedFile = ProcessedFile::create([
            'filename'   => $filename,
            'type'       => 'compressed',
            'path'       => $path,
            'size'       => strlen($compressed),
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

            // Create a temp file path in the system temp directory
            $tempPath = $this->createTempFile($extension);

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
}
