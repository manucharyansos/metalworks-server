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
    public function downloadFile($filePath): BinaryFileResponse|JsonResponse
    {
        if (!$filePath || !is_string($filePath)) {
            return response()->json(['error' => 'Invalid file path.'], 400);
        }
        if (!Storage::disk('public')->exists($filePath)) {
            return response()->json(['error' => 'Ֆայլը չի գտնվել'], 404);
        }
        return response()->download(storage_path("app/public/{$filePath}"));
    }



}
