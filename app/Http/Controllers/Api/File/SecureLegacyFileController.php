<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Models\FactoryOrder;
use App\Models\FactoryOrderFile;
use App\Models\Material;
use App\Models\MaterialGroup;
use App\Models\File;
use App\Models\PmpFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SecureLegacyFileController extends Controller
{
    public function show(Request $request, string $path): BinaryFileResponse|JsonResponse
    {
        $path = $this->normalizePath($path);
        if ($path === null) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $orderFile = File::with('order')->where('path', $path)->first();
        if ($orderFile) {
            if (!$this->canAccessOrderFile($request, $orderFile)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return $this->serve(
                $request,
                $path,
                $orderFile->original_name ?: basename($path)
            );
        }

        $pmpFile = PmpFiles::where('path', $path)->first();
        if ($pmpFile) {
            if (!$this->canAccessPmpFile($request, $pmpFile)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return $this->serve(
                $request,
                $path,
                $pmpFile->original_name ?: basename($path)
            );
        }

        $attachment = \Illuminate\Support\Facades\Schema::hasColumn('factory_order_files', 'path')
            ? FactoryOrderFile::with('factoryOrder.order')->where('path', $path)->first() : null;
        if ($attachment) {
            $step = $attachment->factoryOrder;
            $user = $request->user();
            $allowed = $step && $user && ($user->role?->name === 'admin'
                || (!$user->factory_id && $user->hasPermission('orders.view') && ($user->role?->name !== 'engineer' || (int) $step->order?->creator_id === (int) $user->id))
                || ($user->hasPermission('factory.download') && (int) $step->factory_id === (int) $user->factory_id && (!$step->operator_id || (int) $step->operator_id === (int) $user->id)));
            abort_unless($allowed, 403);
            return $this->serve($request, $path, $attachment->original_name ?: basename($path));
        }
        if (Material::where('image', $path)->exists() || MaterialGroup::where('image', $path)->exists()) {
            abort_unless($request->user()?->hasPermission('materials.view'), 403);
            return $this->serve($request, $path, basename($path));
        }
        return response()->json(['message' => 'File not found'], 404);
    }

    private function canAccessOrderFile(Request $request, File $file): bool
    {
        $user = $request->user();
        $order = $file->order;

        if (!$user || !$order) {
            return false;
        }

        if ($user->role?->name === 'admin') {
            return true;
        }

        if ((int) $order->user_id === (int) $user->id) {
            return true;
        }

        if ($user->role?->name === 'engineer') {
            return $user->hasPermission('orders.view')
                && (int) $order->creator_id === (int) $user->id;
        }

        if (!$user->factory_id && $user->hasPermission('orders.view')) {
            return true;
        }

        if (!$user->factory_id || !$user->hasPermission('factory.download')) {
            return false;
        }

        return FactoryOrder::query()
            ->where('order_id', $order->id)
            ->where('factory_id', $user->factory_id)
            ->where(function ($query) use ($user) {
                $query->whereNull('operator_id')
                    ->orWhere('operator_id', $user->id);
            })
            ->exists();
    }

    private function canAccessPmpFile(Request $request, PmpFiles $file): bool
    {
        return $request->user() && \App\Support\TaskAccess::canDownloadPmp($request->user(), $file);
    }

    private function serve(
        Request $request,
        string $path,
        string $originalName
    ): BinaryFileResponse|JsonResponse {
        $disk = null;
        if (Storage::disk('private')->exists($path)) {
            $disk = 'private';

        }

        if (!$disk) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $fullPath = Storage::disk($disk)->path($path);
        $name = str_replace(["\r", "\n", '"'], '', $originalName);

        if ($request->boolean('download')) {
            return response()->download($fullPath, $name, [
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $response = response()->file($fullPath, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition('inline', $name);

        return $response;
    }

    private function normalizePath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', urldecode($path)), '/');

        if ($path === '' || str_contains($path, "\0") || preg_match('~(^|/)\.\.?(?:/|$)~', $path)) {
            return null;
        }

        return $path;
    }
}
