<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Models\TaskFileRevision;
use App\Support\TaskFileVersions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SecureTaskFileRevisionController extends Controller
{
    public function show(Request $request, TaskFileRevision $taskFileRevision)
    {
        $files = TaskFileVersions::files($request->user(), $taskFileRevision->order);
        abort_unless($files->contains('id', $taskFileRevision->pmp_file_id), 403);
        abort_unless(Storage::disk('private')->exists($taskFileRevision->file_path), 404);
        $headers = ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];
        // Client-provided extensions/content must never turn a revision into
        // executable HTML on the authenticated API origin.
        $headers['Content-Type'] = match (strtolower(pathinfo($taskFileRevision->original_name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf', 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'webp' => 'image/webp', 'gif' => 'image/gif', 'txt', 'csv' => 'text/plain; charset=UTF-8',
            default => 'application/octet-stream',
        };
        $name = str_replace(["\r", "\n", '"'], '', $taskFileRevision->original_name);
        $path = Storage::disk('private')->path($taskFileRevision->file_path);
        if ($request->boolean('download')) return response()->download($path, $name, $headers);
        $response = response()->file($path, $headers);
        $response->setContentDisposition('inline', $name);
        return $response;
    }
}
