<?php

namespace App\Http\Controllers\Api\Workers;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkerResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkersController extends Controller
{
    private const WORKER_ROLE_NAMES = [
        'manager',
        'bend',
        'laser',
        'powder_catting',
        'engineer',
    ];

    private const FACTORY_ROLE_NAMES = [
        'bend',
        'laser',
        'powder_catting',
    ];

    public function index(): AnonymousResourceCollection
    {
        $workers = User::with(['worker', 'role', 'factory'])
            ->whereHas('role', fn ($q) => $q->whereIn('name', self::WORKER_ROLE_NAMES))
            ->orderByDesc('id')
            ->get();

        return WorkerResource::collection($workers);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'last_name'    => 'nullable|string|max:255',
            'email'        => 'required|email|unique:users,email',
            'password'     => 'required|string|min:8|confirmed',
            'role_id'      => [
                'required',
                Rule::exists('roles', 'id')->where(fn ($q) => $q->whereIn('name', self::WORKER_ROLE_NAMES)),
            ],
            'factory_id'   => 'nullable|exists:factories,id',
            'phone'        => 'required|string|max:20',
            'second_phone' => 'nullable|string|max:20',
            'address'      => 'nullable|string|max:255',
        ]);

        $factoryId = $this->validatedFactoryIdForRole((int) $validated['role_id'], $validated['factory_id'] ?? null);

        $user = User::create([
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'password'   => $validated['password'],
            'role_id'    => $validated['role_id'],
            'factory_id' => $factoryId,
        ]);

        $user->worker()->create([
            'last_name'    => $validated['last_name'] ?? null,
            'phone'        => $validated['phone'],
            'second_phone' => $validated['second_phone'] ?? null,
            'address'      => $validated['address'] ?? null,
        ]);

        return response()->json(new WorkerResource($user->load(['worker', 'role', 'factory'])), 201);
    }

    public function show(User $worker): WorkerResource
    {
        $worker->load('worker', 'role', 'factory');
        return new WorkerResource($worker);
    }

    public function update(Request $request, User $worker): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'last_name'    => 'nullable|string|max:255',
            'email'        => ['required', 'email', Rule::unique('users', 'email')->ignore($worker->id)],
            'role_id'      => [
                'required',
                Rule::exists('roles', 'id')->where(fn ($q) => $q->whereIn('name', self::WORKER_ROLE_NAMES)),
            ],
            'factory_id'   => 'nullable|exists:factories,id',
            'phone'        => 'required|string|max:20',
            'second_phone' => 'nullable|string|max:20',
            'address'      => 'nullable|string|max:255',
            'password'     => 'nullable|string|min:8|confirmed',
        ]);

        $factoryId = $this->validatedFactoryIdForRole((int) $validated['role_id'], $validated['factory_id'] ?? null);

        $worker->update([
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'role_id'    => $validated['role_id'],
            'factory_id' => $factoryId,
        ]);

        if (!empty($validated['password'])) {
            $worker->update(['password' => $validated['password']]);
        }

        $worker->worker()->updateOrCreate([], [
            'last_name'    => $validated['last_name'] ?? null,
            'phone'        => $validated['phone'],
            'second_phone' => $validated['second_phone'] ?? null,
            'address'      => $validated['address'] ?? null,
        ]);

        return response()->json([
            'message' => 'Աշխատակիցը հաջողությամբ թարմացվեց',
            'data'    => new WorkerResource($worker->load(['worker', 'role', 'factory'])),
        ]);
    }

    public function destroy(User $worker): JsonResponse
    {
        $worker->worker()?->delete();
        $worker->delete();

        return response()->json(['message' => 'Աշխատակիցը հաջողությամբ ջնջվեց']);
    }

    private function validatedFactoryIdForRole(int $roleId, mixed $factoryId): ?int
    {
        $roleName = Role::whereKey($roleId)->value('name');

        if (in_array($roleName, self::FACTORY_ROLE_NAMES, true)) {
            if (!$factoryId) {
                throw ValidationException::withMessages([
                    'factory_id' => ['Այս աշխատակցի դերի համար արտադրամասը պարտադիր է։'],
                ]);
            }

            return (int) $factoryId;
        }

        return null;
    }
}
