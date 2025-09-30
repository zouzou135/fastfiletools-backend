<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Image;

class ImageController extends Controller
{
    public function compress(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240', // 10MB max
            'quality' => 'integer|between:10,100'
        ]);

        $image = $request->file('image');
        $quality = $request->get('quality', 80);

        $filename = Str::random(40) . '.' . $image->getClientOriginalExtension();

        $img = Image::make($image);
        $compressed = $img->encode($image->getClientOriginalExtension(), $quality);

        Storage::disk('public')->put('compressed/' . $filename, $compressed);

        return response()->json([
            'success' => true,
            'filename' => $filename,
            'url' => Storage::disk('public')->url('compressed/' . $filename),
            'original_size' => $image->getSize(),
            'compressed_size' => strlen($compressed)
        ]);
    }

    public function tune(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240',
            'brightness' => 'integer|between:-100,100',
            'contrast' => 'integer|between:-100,100',
            'saturation' => 'integer|between:-100,100'
        ]);

        $image = $request->file('image');
        $filename = Str::random(40) . '.' . $image->getClientOriginalExtension();

        $img = Image::make($image);

        if ($request->has('brightness')) {
            $img->brightness($request->get('brightness'));
        }

        if ($request->has('contrast')) {
            $img->contrast($request->get('contrast'));
        }

        if ($request->has('saturation')) {
            // Note: Intervention Image doesn't have direct saturation
            // You might need additional processing or different library
        }

        $enhanced = $img->encode($image->getClientOriginalExtension());
        Storage::disk('public')->put('enhanced/' . $filename, $enhanced);

        return response()->json([
            'success' => true,
            'filename' => $filename,
            'url' => Storage::disk('public')->url('enhanced/' . $filename)
        ]);
    }

    public function convertToPdf(Request $request)
    {
        $request->validate([
            'images' => 'required|array',
            'images.*' => 'image|max:10240'
        ]);

        $images = $request->file('images');
        $pdfFilename = Str::random(40) . '.pdf';

        $pdf = new \TCPDF();

        foreach ($images as $image) {
            $img = Image::make($image);
            $tempPath = storage_path('app/temp/' . Str::random(20) . '.jpg');
            $img->save($tempPath);

            $pdf->AddPage();
            $pdf->Image($tempPath, 10, 10, 190, 0, 'JPG');

            unlink($tempPath); // Clean up temp file
        }

        $pdfContent = $pdf->Output('', 'S');
        Storage::disk('public')->put('pdfs/' . $pdfFilename, $pdfContent);

        return response()->json([
            'success' => true,
            'filename' => $pdfFilename,
            'url' => Storage::disk('public')->url('pdfs/' . $pdfFilename)
        ]);
    }
}
