<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;

class FileController extends Controller
{
    public function downloadFile($filename)
    {
        if (!Storage::disk('public')->exists($filename)) {
            return response()->json(['error' => 'Ֆայլը չի գտնվել'], 404);
        }

        return response()->download(storage_path("app/public/{$filename}"));
    }
}
