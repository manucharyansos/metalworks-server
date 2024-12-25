<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;
class FileController extends Controller
{

//    public function downloadFile($filePath): BinaryFileResponse|JsonResponse
//    {
//        $decodedPath = urldecode($filePath);
//        if (!$decodedPath) {
//            return response()->json(['error' => 'Invalid file path.'], 400);
//        }
//        if (!Storage::disk('public')->exists($decodedPath)) {
//            return response()->json(['error' => 'Ֆայլը չի գտնվել'], 404);
//        }
//        return response()->download(storage_path("app/public/{$decodedPath}"));
//    }
    public function downloadFile($filePath): BinaryFileResponse|JsonResponse
    {
        // Decode the file path from the URL
        $decodedPath = urldecode($filePath);
        if (!$decodedPath) {
            return response()->json(['error' => 'Invalid file path.'], 400);
        }

        // Check if the file exists in the storage
        if (!Storage::disk('public')->exists($decodedPath)) {
            return response()->json(['error' => 'File not found in storage'], 404);
        }

        // Get the file information from the database based on the file path
        $file = File::where('path', $decodedPath)->first();

        if (!$file) {
            return response()->json(['error' => 'File not found in the database.'], 404);
        }

        // Ensure original file name exists and is correct
        $originalFileName = $file->original_name;

        // Get the full file path from storage
        $fileFullPath = storage_path("app/public/{$decodedPath}");

        if (!file_exists($fileFullPath)) {
            return response()->json(['error' => 'File not found in storage.'], 404);
        }

        // Define MIME types based on the file extension
        $extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
        $mimeTypes = [
            'pdf'   => 'application/pdf',
            'png'   => 'image/png',
            'jpeg'  => 'image/jpeg',
            'jpg'   => 'image/jpeg',
            'eps'   => 'application/postscript',
            'step'  => 'application/step',
            'sldprt'=> 'application/octet-stream',
            'sldasm'=> 'application/octet-stream',
            'dxf'   => 'application/dxf',
        ];

        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

        // Return the file with the correct name and MIME type
        return response()->download($fileFullPath, $originalFileName, [
            'Content-Type' => $mimeType,
        ]);
    }


}
