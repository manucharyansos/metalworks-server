<?php

namespace App\Http\Controllers\Api\Workers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class WorkersController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::whereIn('role_id', [5, 6, 8])
            ->with('client')
            ->orderBy('id','desc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Workers fetched',
            'data' => $users,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email'     => ['required','email','unique:users,email'],
            'password'  => ['required','string','min:6','confirmed'],
            'role_id'   => ['required','exists:roles,id'],
            'name'      => ['required','string'],
            'type'      => ['required','string'],
            'phone'     => ['required','string'],
            'last_name' => ['nullable','string'],
            'second_phone' => ['nullable','string'],
            'address'   => ['nullable','string'],
        ]);

        $user = User::create([
            'name'     => $request->string('name'),
            'email'    => $request->string('email'),
            'password' => Hash::make($request->string('password')),
            'role_id'  => $request->integer('role_id'),
        ]);

        $clientData = [
            'type'          => $request->string('type'),
            'name'          => $request->string('name'),
            'last_name'     => $request->string('last_name'),
            'phone'         => $request->string('phone'),
            'second_phone'  => $request->string('second_phone'),
            'address'       => $request->string('address'),
        ];
        $user->client()->create($clientData);

        return response()->json([
            'status' => true,
            'message' => 'Worker created',
            'data' => $user->load('client'),
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = User::with('client')->findOrFail($id);

        $request->validate([
            'email'     => ['required','email', Rule::unique('users','email')->ignore($user->id)],
            'role_id'   => ['required','exists:roles,id'],
            'name'      => ['required','string'],
            'type'      => ['required','string'],
            'phone'     => ['required','string'],
            'last_name' => ['nullable','string'],
            'second_phone' => ['nullable','string'],
            'address'   => ['nullable','string'],
            // optional password change
            'password'  => ['nullable','string','min:6','confirmed'],
        ]);

        $user->update([
            'name'    => $request->string('name'),
            'email'   => $request->string('email'),
            'role_id' => $request->integer('role_id'),
        ]);

        if ($request->filled('password')) {
            $user->update([
                'password' => Hash::make($request->string('password')),
            ]);
        }

        $clientData = [
            'type'          => $request->string('type'),
            'name'          => $request->string('name'),
            'last_name'     => $request->string('last_name'),
            'phone'         => $request->string('phone'),
            'second_phone'  => $request->string('second_phone'),
            'address'       => $request->string('address'),
        ];

        $user->client()->updateOrCreate(
            ['user_id' => $user->id],
            $clientData
        );

        return response()->json([
            'status' => true,
            'message' => 'Worker updated',
            'data' => $user->fresh()->load('client'),
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $user = User::with('client')->findOrFail($id);
        if ($user->client) $user->client->delete();
        $user->delete();

        return response()->json([
            'status' => true,
            'message' => 'Worker deleted',
        ]);
    }
}
