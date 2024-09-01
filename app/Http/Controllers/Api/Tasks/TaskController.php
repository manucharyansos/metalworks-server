<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    // Get all tasks
    public function index(): JsonResponse
    {
        return response()->json(Task::with('role')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'role_ids' => 'required|array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $task = Task::create($request->only('name', 'description'));

        // Attach multiple roles
        $task->roles()->attach($request->role_ids);

        return response()->json($task->load('roles'), 201);
    }

    public function show($id): JsonResponse
    {
        $task = Task::with('role')->findOrFail($id);
        return response()->json($task);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string|max:255',
            'role_ids' => 'sometimes|required|array',
            'role_ids.*' => 'exists:roles,id',
        ]);

        $task = Task::findOrFail($id);
        $task->update($request->only('name', 'description'));

        // Sync roles
        if ($request->has('role_ids')) {
            $task->roles()->sync($request->role_ids);
        }

        return response()->json($task->load('roles'));
    }

    public function destroy($id): JsonResponse
    {
        $task = Task::findOrFail($id);
        $task->delete();

        return response()->json(null, 204);
    }
}
