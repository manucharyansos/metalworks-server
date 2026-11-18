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
        $file = $files->firstWhere('id', $taskFileRevision->pmp_file_id);
        // A view-only model (SolidWorks, STEP) reaches workshop staff only through the app's viewer.
        $viewOnly = !$request->boolean('download') && \App\Support\ProjectFilePolicy::isPreviewRequest($request) && ($file['can_view'] ?? false);
        abort_unless(($file['can_download'] ?? false) || $viewOnly, 403);
        abort_unless(\App\Support\ProjectFilePolicy::operatorMayReceive($request, $taskFileRevision->original_name), 403);
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
