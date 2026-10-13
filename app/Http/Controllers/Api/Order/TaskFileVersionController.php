<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\{Order, PmpFiles};
use App\Support\{TaskFileVersions, TaskNotifier, TaskRouting};
use Illuminate\Http\Request;

class TaskFileVersionController extends Controller
{
    public function index(Request $request, Order $order)
    {
        return response()->json(['files' => TaskFileVersions::files($request->user(), $order)]);
    }

    public function store(Request $request, Order $order, PmpFiles $file)
    {
        TaskRouting::authorize($request->user(), $order);
        $data = $request->validate(['file' => 'required|file|max:10240', 'note' => 'nullable|string|max:1000', 'previous_revision_id' => 'present|nullable|integer']);
        return $this->change($request, $order, $file, $data, true);
    }

    public function activate(Request $request, Order $order, PmpFiles $file)
    {
        TaskRouting::authorize($request->user(), $order);
        $data = $request->validate(['revision_id' => 'required|integer', 'previous_revision_id' => 'present|nullable|integer']);
        return $this->change($request, $order, $file, $data, false);
    }

    private function change(Request $request, Order $order, PmpFiles $file, array $data, bool $upload)
    {
        [$order, $revision, $steps] = TaskFileVersions::update($request->user(), $order, $file, $data, $upload ? $request->file('file') : null);
        foreach ($steps as $step) TaskNotifier::fileVersionChanged($request->user(), $order, $step, $revision);
        return response()->json(['order' => TaskRouting::order($order), 'files' => TaskFileVersions::files($request->user(), $order)], $upload ? 201 : 200);
    }
}
