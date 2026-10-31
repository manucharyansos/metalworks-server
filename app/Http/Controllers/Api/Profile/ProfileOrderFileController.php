<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TaskFileRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileOrderFileController extends Controller
{
    private function ownedOrder(Request $request, int $orderId): Order
    {
        // Order's company scope is applied in addition to customer ownership.
        return Order::where('user_id', $request->user()->id)->findOrFail($orderId);
    }

    private function files(Order $order): array
    {
        $files = $order->files()->get()->map(fn ($file) => [
            'key' => 'order-'.$file->id,
            'original_name' => $file->original_name,
            'path' => $file->path,
        ])->all();

        foreach ($order->selectedFiles()->with('pmpFile')->get() as $selected) {
            $file = $selected->pmpFile;
            if (!$file) continue;
            $revision = $selected->task_file_revision_id
                ? TaskFileRevision::where('order_id', $order->id)->where('pmp_file_id', $file->id)->find($selected->task_file_revision_id)
                : null;
            // A broken pinned revision must never silently serve a different drawing.
            if ($selected->task_file_revision_id && !$revision) continue;
            $files[] = [
                'key' => 'selected-'.$selected->id,
                'original_name' => $revision?->original_name ?: $file->original_name,
                'path' => $revision?->file_path ?: $file->path,
                'quantity' => $selected->quantity,
            ];
        }
        return $files;
    }

    public function index(Request $request, int $orderId)
    {
        $order = $this->ownedOrder($request, $orderId);
        return response()->json(['files' => array_map(function ($file) {
            unset($file['path']);
            return $file;
        }, $this->files($order))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, int $orderId, string $fileKey)
    {
        $order = $this->ownedOrder($request, $orderId);
        $file = collect($this->files($order))->firstWhere('key', $fileKey);
        abort_unless($file, 404);
        $path = ltrim(str_replace('\\', '/', urldecode((string) $file['path'])), '/');
        abort_if(!$path || in_array('..', explode('/', $path), true) || str_contains($path, "\0"), 404);
        $disk = Storage::disk('private');
        abort_unless($disk->exists($path), 404);
        $name = str_replace(["\r", "\n", '"'], '', $file['original_name'] ?: basename($path));
        $mime = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf', 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'webp' => 'image/webp', 'gif' => 'image/gif', 'txt', 'csv', 'dxf' => 'text/plain; charset=UTF-8',
            default => 'application/octet-stream',
        };
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $mime];
        if ($request->boolean('download')) return response()->download($disk->path($path), $name, $headers);
        $response = response()->file($disk->path($path), $headers);
        $response->setContentDisposition('inline', $name);
        return $response;
    }
}
