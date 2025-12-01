<?php

namespace App\Http\Controllers\Api\Workers;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkerResource;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class WorkersController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $workers = User::with(['worker', 'role'])
            ->whereHas('role', fn($q) => $q->whereIn('id', [4, 5, 6, 7, 8]))
            ->orderByDesc('id')
            ->get();

        return WorkerResource::collection($workers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'last_name'    => 'nullable|string|max:255',
            'email'        => 'required|email|unique:users,email',
            'password'     => 'required|string|min:6|confirmed',
            'role_id'      => 'required|exists:roles,id',
            'factory_id'   => 'nullable|exists:factories,id',
            'phone'        => 'required|string|max:20',
            'second_phone' => 'nullable|string|max:20',
            'address'      => 'nullable|string',
        ]);

        $user = User::create([
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'password'   => Hash::make($validated['password']),
            'role_id'    => $validated['role_id'],
            'factory_id' => $validated['factory_id'] ?? null,
        ]);

        $user->worker()->create([
            'last_name'    => $validated['last_name'],
            'phone'        => $validated['phone'],
            'second_phone' => $validated['second_phone'],
            'address'      => $validated['address'],
        ]);

        return response()->json(new WorkerResource($user->load('worker')), 201);
    }

    public function show(User $worker): WorkerResource
    {
        $worker->load('worker', 'role');
        return new WorkerResource($worker);
    }

    public function update(Request $request, User $worker): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'last_name'    => 'nullable|string|max:255',
            'email'        => ['required', 'email', Rule::unique('users', 'email')->ignore($worker->id)],
            'role_id'      => 'required|exists:roles,id',
            'factory_id'   => 'nullable|exists:factories,id',
            'phone'        => 'required|string|max:20',
            'second_phone' => 'nullable|string|max:20',
            'address'      => 'nullable|string',
            'password'     => 'nullable|string|min:6|confirmed',
        ]);

        $worker->update([
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'role_id'    => $validated['role_id'],
            'factory_id' => $validated['factory_id'] ?? null,
        ]);

        if ($request->filled('password')) {
            $worker->update(['password' => Hash::make($validated['password'])]);
        }

        $worker->worker()->updateOrCreate([], [
            'last_name'    => $validated['last_name'],
            'phone'        => $validated['phone'],
            'second_phone' => $validated['second_phone'],
            'address'      => $validated['address'],
        ]);

        return response()->json([
            'message' => 'Աշխատակիցը հաջողությամբ թարմացվեց',
            'data'    => new WorkerResource($worker->load('worker'))
        ]);
    }

    public function destroy(User $worker): JsonResponse
    {
        $worker->worker()?->delete();
        $worker->delete();

        return response()->json(['message' => 'Աշխատակիցը հաջողությամբ ջնջվեց']);
    }
}
