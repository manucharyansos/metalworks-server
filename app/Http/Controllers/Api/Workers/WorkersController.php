<?php

namespace App\Http\Controllers\Api\Workers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $users = User::whereIn('role_id', [5, 6])->with('client')->get();

        if ($users->isEmpty()) {
            return response()->json([
                'message' => 'No users found with role_id 3 or 6'
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
    public function destroy(string $id)
    {
        //
    }

    public function getArr(Request $request): array
    {
        $validatedClientData = $request->validate([
            'type' => 'required|in:laser,bend,Laser operator,Bend operator',
            'name' => 'required|string',
            'last_name' => 'nullable|string',
            'phone' => 'required|string',
            'second_phone' => 'nullable|string',
            'address' => 'nullable|string',
        ]);

        return $validatedClientData;
    }


}
