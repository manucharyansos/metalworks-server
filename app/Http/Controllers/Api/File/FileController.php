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
        $decodedPath = urldecode($filePath); // Ապակոդավորում

        if (!$decodedPath) {
            return response()->json(['error' => 'Invalid file path.'], 400);
        }

        // Ստուգել, որ ֆայլը կա storage-ում
        if (!Storage::disk('public')->exists($decodedPath)) {
            return response()->json(['error' => 'File not found in storage'], 404);
        }

        // Տվյալների բազայից ֆայլի ստուգում
        $file = File::where('path', $decodedPath)->first();
        if (!$file) {
            return response()->json(['error' => 'File not found in the database.'], 404);
        }

        $originalFileName = $file->original_name;
        $fileFullPath = storage_path("app/public/{$decodedPath}");

        if (!file_exists($fileFullPath)) {
            return response()->json(['error' => 'File not found in storage.'], 404);
        }

        return response()->download($fileFullPath, $originalFileName);
    }


}
