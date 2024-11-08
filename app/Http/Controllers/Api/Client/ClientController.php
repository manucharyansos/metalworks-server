<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $clients = Client::all();
        return response()->json($clients);
    }


    /**
     * Store a newly created resource in storage.
     */
//    public function store(Request $request): JsonResponse
//    {
//        $validatedData = $request->validate([
//            'user_id' => 'required|exists:users,id',
//            'email' => 'required|email|unique:users,email',
//            'type' => 'required|in:physPerson,legalEntity',
//        ]);
//
//        if ($validatedData['type'] === 'physPerson') {
//            $validatedData = array_merge($validatedData, $request->validate([
//                'name' => 'required|string',
//                'last_name' => 'nullable|string',
//                'phone' => 'required|string',
//                'second_phone' => 'nullable|string',
//                'address' => 'nullable|string',
//            ]));
//        }
//        else if ($validatedData['type'] === 'legalEntity') {
//            $validatedData = array_merge($validatedData, $request->validate([
//                'name' => 'required|string',
//                'phone' => 'required|string',
//                'address' => 'nullable|string',
//                'company_name' => 'required|string',
//                'AVC' => 'required|string',
//                'accountant' => 'required|string',
//            ]));
//        }
//
//        $client = Client::create($validatedData);
//
//        return response()->json($client, 201);
//    }

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
    public function update(Request $request): JsonResponse
    {
        // Validate the basic type field
        $validatedData = $request->validate([
            'type' => 'required|in:physPerson,legalEntity',
        ]);

        // Additional validation based on client type
        if ($validatedData['type'] === 'physPerson') {
            $validatedData = array_merge($validatedData, $request->validate([
                'name' => 'required|string',
                'last_name' => 'nullable|string',
                'phone' => 'required|string',
                'second_phone' => 'nullable|string',
                'address' => 'nullable|string',
            ]));
        } elseif ($validatedData['type'] === 'legalEntity') {
            $validatedData = array_merge($validatedData, $request->validate([
                'name' => 'required|string',
                'phone' => 'required|string',
                'address' => 'nullable|string',
                'company_name' => 'required|string',
                'AVC' => 'required|string',
                'accountant' => 'required|string',
            ]));
        }

        // Update or create client data for the authenticated user
        $user = auth()->user();
        $client = $user->client()->updateOrCreate(
            ['user_id' => $user->id],
            $validatedData
        );

        return response()->json($client, 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client): JsonResponse
    {
        $client->delete();

        return response()->json(['message' => 'Client deleted successfully'], 200);
    }
}
