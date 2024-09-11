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
    public function store(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'number' => 'required|string|max:255',
            'AVC' => 'required|string|max:255',
            'group' => 'required|string|max:255',
            'VAT_payer' => 'required|boolean',
            'legal_address' => 'required|string|max:255',
            'valid_address' => 'required|string|max:255',
            'VAT_of_the_manager' => 'required|string|max:255',
            'leadership_position' => 'required|string|max:255',
            'accountants_VAT' => 'required|string|max:255',
            'accountant_position' => 'required|string|max:255',
            'registration_of_the_individual' => 'required|string|max:255',
            'type_of_ID_card' => 'required|string|max:255',
            'passport_number' => 'required|string|max:255',
            'contract' => 'required|string|max:255',
            'contract_date' => 'required|string|max:255',
            'sales_discount_percentage' => 'required|string|max:255',
            'email_address' => 'required|email|unique:clients,email_address',
//            'user_id' => 'required|exists:users,id',
        ]);

        $client = Client::create($validatedData);

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
    public function update(Request $request, Client $client): JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'number' => 'sometimes|required|string',
            'AVC' => 'sometimes|required|string|max:255',
            'group' => 'sometimes|required|string|max:255',
            'VAT_payer' => 'sometimes|required|string|max:255',
            'legal_address' => 'sometimes|required|string|max:255',
            'valid_address' => 'sometimes|required|string|max:255',
            'VAT_of_the_manager' => 'sometimes|required|string|max:255',
            'leadership_position' => 'sometimes|required|string|max:255',
            'accountants_VAT' => 'sometimes|required|string|max:255',
            'accountant_position' => 'sometimes|required|string|max:255',
            'registration_of_the_individual' => 'sometimes|required|string|max:255',
            'type_of_ID_card' => 'sometimes|required|string|max:255',
            'passport_number' => 'sometimes|required|string|max:255',
            'contract' => 'sometimes|required|string|max:255',
            'contract_date' => 'sometimes|required|string|max:255',
            'sales_discount_percentage' => 'sometimes|required|string|max:255',
            'email_address' => 'sometimes|required|email|unique:clients,email_address,' . $client->id,
//            'user_id' => 'sometimes|required|exists:users,id',
        ]);

        $client->update($validatedData);

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
