<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function index(): JsonResponse
    {
        $clients = Client::with('user:id,name,email')
            ->whereHas('user.role', fn ($query) => $query->where('name', 'authenticatedUser'))
            ->orderByDesc('id')
            ->get();

        return ClientResource::collection($clients)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'type' => 'required|in:physPerson,legalEntity',
        ]);

        $clientData = $this->validateClientData($request);
        $roleId = Role::where('name', 'authenticatedUser')->value('id');

        if (!$roleId) {
            Log::error('Client creation failed because authenticatedUser role is missing');

            return response()->json([
                'message' => 'Հաճախորդի ստեղծումը ժամանակավորապես անհասանելի է։',
            ], 500);
        }

        $client = DB::transaction(function () use ($request, $clientData, $roleId): Client {
            $user = User::create([
                'name' => $clientData['name'],
                'email' => $request->email,
                'password' => $request->password,
                'role_id' => $roleId,
            ]);

            return $user->client()->create($clientData);
        });

        return response()->json(new ClientResource($client->load('user')), 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->assertClientTarget($user);
        $client = $user->client;

        if (!$client) {
            return response()->json(['message' => 'Հաճախորդը չի գտնվել'], 404);
        }

        return new ClientResource($client->load('user'));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->assertClientTarget($user);
        $client = $user->client;

        if (!$client) {
            return response()->json(['message' => 'Հաճախորդը չի գտնվել'], 404);
        }

        $request->validate([
            'type' => 'required|in:physPerson,legalEntity',
        ]);

        $validatedData = $this->validateClientData($request);

        DB::transaction(function () use ($user, $client, $validatedData): void {
            $user->update(['name' => $validatedData['name']]);
            $client->update($validatedData);
        });

        return response()->json([
            'message' => 'Հաճախորդը հաջողությամբ թարմացվեց',
            'client'  => new ClientResource($client->fresh()->load('user')),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->assertClientTarget($user);
        $client = $user->client;

        if (!$client) {
            return response()->json(['message' => 'Հաճախորդը չի գտնվել'], 404);
        }

        DB::transaction(function () use ($client, $user): void {
            $client->delete();
            $user->delete();
        });

        return response()->json(['message' => 'Հաճախորդը հաջողությամբ ջնջվեց']);
    }

    private function assertClientTarget(User $user): void
    {
        $user->loadMissing('role');

        abort_unless(
            $user->role?->name === 'authenticatedUser',
            404,
            'Client not found'
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
