<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileController extends Controller
{
    public function downloadFile($filename): BinaryFileResponse|JsonResponse
    {
        if (!Storage::disk('public')->exists($filename)) {
            return response()->json(['error' => 'Ֆայլը չի գտնվել'], 404);
        }

        return response()->download(storage_path("app/public/{$filename}"));
    }

//    public function downloadFile($path): BinaryFileResponse|JsonResponse
//    {
//        $filePath = storage_path('app/public/' . $path);
//
//        if (!Storage::disk('public')->exists($path)) {
//            return response()->json(['error' => 'File not found'], 404)
//                ->header('Access-Control-Allow-Origin', 'http://localhost:3000')
//                ->header('Access-Control-Allow-Methods', 'GET');
//        }
//
//        return response()->download($filePath);
//    }

}
