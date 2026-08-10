<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Models\FactoryOrder;
use App\Models\PmpFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SecurePmpFileController extends Controller
{
    public function show(Request $request, PmpFiles $file): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();

        if (!$user || !$this->canAccess($request, $file)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $disk = $this->resolveDisk($file->path);
        if (!$disk) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $fullPath = Storage::disk($disk)->path($file->path);
        $name = str_replace(["\r", "\n", '"'], '', $file->original_name ?: basename($file->path));

        if ($request->boolean('download')) {
            return response()->download($fullPath, $name, [
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $response = response()->file($fullPath, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition('inline', $name);

        return $response;
    }

    private function canAccess(Request $request, PmpFiles $file): bool
    {
        $user = $request->user();

        if ($user->role?->name === 'admin') {
            return true;
        }

        if (!$user->factory_id && $user->hasPermission('pmp_files.view')) {
            return true;
        }

        if (
            !$user->factory_id ||
            !$user->hasPermission('factory.download') ||
            (int) $file->factory_id !== (int) $user->factory_id
        ) {
            return false;
        }

        return FactoryOrder::query()
            ->where('factory_id', $user->factory_id)
            ->where(function ($query) use ($user) {
                $query->whereNull('operator_id')
                    ->orWhere('operator_id', $user->id);
            })
            ->whereHas('files', fn ($query) => $query->whereKey($file->id))
            ->exists();
    }

    private function resolveDisk(string $path): ?string
    {
        if (Storage::disk('private')->exists($path)) {
            return 'private';
        }

        if (Storage::disk('public')->exists($path)) {
            return 'public';
        }

        return null;
    }
}
