<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(): JsonResponse
    {
        $clients = Client::with('user:id,name,email')
            ->whereRelation('user', 'role_id', 3)
            ->orderByDesc('id')
            ->get();

        return ClientResource::collection($clients)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'type' => 'required|in:physPerson,legalEntity',
        ]);

        $clientData = $this->validateClientData($request);

        $user = User::create([
            'name' => $clientData['name'],
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role_id' => 3,
        ]);

        $client = $user->client()->create($clientData);

        return response()->json(new ClientResource($client->load('user')), 201);
    }

    public function show(User $user): JsonResponse
    {
        $client = $user->client;

        if (!$client) {
            return response()->json(['message' => 'Հաճախորդը չի գտնվել'], 404);
        }

        return new ClientResource($client->load('user'));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $client = $user->client;

        if (!$client) {
            return response()->json(['message' => 'Հաճախորդը չի գտնվել'], 404);
        }

        $request->validate([
            'type' => 'required|in:physPerson,legalEntity',
        ]);

        $validatedData = $this->validateClientData($request);

        $user->update(['name' => $validatedData['name']]);

        $client->update($validatedData);

        return response()->json([
            'message' => 'Հաճախորդը հաջողությամբ թարմացվեց',
            'client'  => new ClientResource($client->load('user'))
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(User $user): JsonResponse
    {
        $client = $user->client;

        if (!$client) {
            return response()->json(['message' => 'Հաճախորդը չի գտնվել'], 404);
        }

        $client->delete();
        $user->delete();

        return response()->json(['message' => 'Հաճախորդը հաջողությամբ ջնջվեց']);
    }

    private function validateClientData(Request $request): array
    {
        $type = $request->type;

        $common = $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'required|string|max:20',
            'address' => 'nullable|string',
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
