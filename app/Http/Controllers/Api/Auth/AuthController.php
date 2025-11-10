<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|min:3|max:255',
                'email' => 'required|email|unique:users',
                'password' => 'required|min:6|confirmed',
            ]);

            $validatedData['role_id'] = 3;
            $validatedData['password'] = bcrypt($validatedData['password']);

            $user = User::create($validatedData);

            $user->load('role');

            $accessToken = $user->createToken('auth_token')->plainTextToken;

            return response()->json(['user' => $user, 'access_token' => $accessToken]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Registration failed. Please try again later.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function login(Request $request): JsonResponse
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            if (Auth::attempt($credentials)) {
                $user = User::with('role')->where('email', $credentials['email'])->first();
                $accessToken = $user->createToken('auth_token')->plainTextToken;

                return response()->json(['user' => $user, 'access_token' => $accessToken]);
            }

            return response()->json(['error' => 'Invalid credentials'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => $e], 500);
        }
    }


    public function logout(): JsonResponse
    {
        Auth::user()->tokens()->delete();
        return response()->json('user logged out', 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // добавили factory
        $user->load(['role', 'permissions', 'role.permissions', 'factory']);

        if ($user->role && $user->role->name === 'admin') {
            $permissions = Permission::pluck('slug');
        } else {
            $userPermissions = $user->permissions->pluck('slug');
            $rolePermissions = $user->role
                ? $user->role->permissions->pluck('slug')
                : collect();

            $permissions = $userPermissions
                ->merge($rolePermissions)
                ->unique()
                ->values();
        }

        return response()->json([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role ? [
                'id'   => $user->role->id,
                'name' => $user->role->name,
            ] : null,
            'factory_id' => $user->factory_id,
            'factory' => $user->factory ? [
                'id'   => $user->factory->id,
                'name' => $user->factory->name,
            ] : null,
            'permissions' => $permissions,
        ], 200);
    }

}
