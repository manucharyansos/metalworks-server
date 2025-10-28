<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
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
        if (auth()->check()) {
            $user = auth()->user();
            $user->load('role');

            return response()->json($user, 200);
        }
        return response()->json(['error' => 'Unauthorized'], 401);
    }

}
