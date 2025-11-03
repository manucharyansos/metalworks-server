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
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $clients = Client::with(['user:id,name,email'])->get();

        return ClientResource::collection($clients)->response();
    }


    /**
     * Store a newly created resource in storage.
     */


    public function store(Request $request): JsonResponse
    {
        $validatedUserData = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $validatedClientData = $this->getArr($request);

        $user = User::create([
            'name' => $validatedClientData['name'],
            'email' => $validatedUserData['email'],
            'password' => bcrypt($validatedUserData['password']),
            'role_id' => 3
        ]);

        $client = $user->client()->create($validatedClientData);

        return response()->json($client, 201);
    }


    /**
     * Display the specified resource.
     */
    public function show(Client $client): JsonResponse
    {
        return response()->json($client, 200);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validatedData = $this->getArr($request);

        $user = User::findOrFail($id);
        $user->update(['name' => $validatedData['name']]);
        $user->client()->updateOrCreate(
            ['user_id' => $user->id],
            $validatedData
        );

        return response()->json([
            'user' => $user->load('client'),
            'message' => 'Հաճախորդը հաջողությամբ թարմացվեց',
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client): JsonResponse
    {
        $client->delete();

        return response()->json(['message' => 'Client deleted successfully'], 200);
    }

    /**
     * @param Request $request
     * @return array
     */
    public function getArr(Request $request): array
    {
        $validatedClientData = $request->validate([
            'type' => 'required|in:physPerson,legalEntity', // Վավերացնում ենք տեսակը
        ]);

        if ($validatedClientData['type'] === 'physPerson') {
            $validatedClientData = array_merge($validatedClientData, $request->validate([
                'name' => 'required|string',
                'last_name' => 'nullable|string',
                'phone' => 'required|string',
                'second_phone' => 'nullable|string',
                'address' => 'nullable|string',
            ]));
        } elseif ($validatedClientData['type'] === 'legalEntity') {
            $validatedClientData = array_merge($validatedClientData, $request->validate([
                'name' => 'required|string',
                'phone' => 'required|string',
                'address' => 'nullable|string',
                'company_name' => 'required|string',
                'AVC' => 'required|string',
                'accountant' => 'required|string',
            ]));
        }

        return $validatedClientData;
    }

}
