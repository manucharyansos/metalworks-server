<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\CompanyMembership;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function index(): JsonResponse
    {
        $clients = Client::with('user:id,name,email')
            ->where('access_enabled', true)
            ->orderByDesc('id')
            ->get();

        return ClientResource::collection($clients)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $existing = User::withoutGlobalScope('company')->where('email', $request->email)->first();
        if ($existing && Client::where('user_id', $existing->id)->where('access_enabled', true)->exists()) {
            throw ValidationException::withMessages(['email' => ['This email is already registered. Company access is managed by the platform administrator.']]);
        }
        $request->validate([
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($existing?->id)],
            'password' => ($existing ? 'nullable' : 'required') . '|string|min:8|confirmed',
            'type' => 'required|in:physPerson,legalEntity',
        ]);

        $clientData = $this->validateClientData($request);
        $roleId = Role::where('name', 'authenticatedUser')->value('id');

        if (!$roleId) {
            Log::error('Client creation failed because authenticatedUser role is missing');

            return response()->json([
                'message' => 'Պատվիրատուի ստեղծումը ժամանակավորապես անհասանելի է։',
            ], 500);
        }

        $client = DB::transaction(function () use ($request, $clientData, $roleId, $existing): Client {
            $user = $existing ?: User::create([
                'name' => $clientData['name'],
                'email' => $request->email,
                'password' => $request->password,
                'role_id' => $roleId,
            ]);

            CompanyMembership::firstOrCreate(['company_id' => app(CompanyContext::class)->id(), 'user_id' => $user->id], ['role_id' => $roleId, 'factory_id' => null, 'is_active' => true]);
            app(CompanyContext::class)->forgetMembership($user->id);
            return $user->client()->updateOrCreate([], [...$clientData, 'access_enabled' => true]);
        });

        return response()->json(new ClientResource($client->load('user')), 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->assertClientTarget($user);
        $client = $user->client;

        if (!$client) {
            return response()->json(['message' => 'Պատվիրատուն չի գտնվել'], 404);
        }

        return (new ClientResource($client->load('user')))->response();
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->assertClientTarget($user);
        $client = $user->client;

        if (!$client) {
            return response()->json(['message' => 'Պատվիրատուն չի գտնվել'], 404);
        }

        $request->validate([
            'type' => 'required|in:physPerson,legalEntity',
        ]);

        $validatedData = $this->validateClientData($request);

        DB::transaction(function () use ($user, $client, $validatedData): void {
            if (request()->user()->is_platform_admin || $user->memberships()->where('is_active', true)->count() <= 1) $user->update(['name' => $validatedData['name']]);
            $client->update($validatedData);
        });

        return response()->json([
            'message' => 'Պատվիրատուն հաջողությամբ թարմացվեց',
            'client'  => new ClientResource($client->fresh()->load('user')),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->assertClientTarget($user);
        $client = $user->client;

        if (!$client) {
            return response()->json(['message' => 'Պատվիրատուն չի գտնվել'], 404);
        }

        DB::transaction(function () use ($client, $user): void {
            $client->update(['access_enabled' => false]);
            $membership = app(CompanyContext::class)->membership($user->id);
            if ($membership && !array_intersect(\App\Support\MembershipAssignments::roleNames($membership), \App\Support\MembershipAssignments::STAFF_ROLES)) $membership->update(['is_active' => false]);
            app(\App\Support\CompanyContext::class)->forgetMembership($user->id);
        });

        return response()->json(['message' => 'Պատվիրատուն հաջողությամբ ջնջվեց']);
    }

    private function assertClientTarget(User $user): void
    {
        $user->loadMissing('role');

        abort_unless(
            Client::where('user_id', $user->id)->where('access_enabled', true)->exists(),
            404,
            'Customer not found'
        );
    }

    private function validateClientData(Request $request): array
    {
        $type = $request->type;

        $common = $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'required|string|max:20',
            'address' => 'nullable|string|max:255',
        ]);

        if ($type === 'physPerson') {
            $specific = $request->validate([
                'last_name'    => 'nullable|string|max:255',
                'second_phone' => 'nullable|string|max:20',
            ]);
        } else {
            $specific = $request->validate([
                'company_name' => 'required|string|max:255',
                'AVC'          => 'required|string|max:50',
                'accountant'   => 'required|string|max:255',
            ]);
        }

        return array_merge(['type' => $type], $common, $specific);
    }
}
