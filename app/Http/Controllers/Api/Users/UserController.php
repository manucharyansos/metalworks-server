<?php

namespace App\Http\Controllers\Api\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $users = User::where('role_id', 3)->get();
        if ($users->isEmpty()) {
            return response()->json([
                'message' => 'No users found with role_id 3'
            ], 404);
        }

        return response()->json($users);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validatedData = $request->validate([
            'user_id' => 'required|exists:users,id',
            'email' => 'required|email|unique:users,email',
            'type' => 'required|in:physPerson,legalEntity',
        ]);

        if ($validatedData['type'] === 'physPerson') {
            $validatedData = array_merge($validatedData, $request->validate([
                'name' => 'required|string',
                'last_name' => 'nullable|string',
                'phone' => 'required|string',
                'second_phone' => 'nullable|string',
                'address' => 'nullable|string',
            ]));
        }
        else if ($validatedData['type'] === 'legalEntity') {
            $validatedData = array_merge($validatedData, $request->validate([
                'name' => 'required|string',
                'phone' => 'required|string',
                'address' => 'nullable|string',
                'company_name' => 'required|string',
                'AVC' => 'required|string',
                'accountant' => 'required|string',
            ]));
        }

        $client = User::create($validatedData);

        return response()->json($client, 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
