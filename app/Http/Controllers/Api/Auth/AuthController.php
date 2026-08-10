<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $rateLimitKey = 'register|' . $request->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json([
                'message' => 'Too many registration attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($rateLimitKey),
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 600);

        $validatedData = $request->validate([
            'name' => 'required|min:3|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $roleId = Role::where('name', 'authenticatedUser')->value('id');
        if (!$roleId) {
            Log::error('Registration failed because authenticatedUser role is missing');

            return response()->json([
                'message' => 'Registration is temporarily unavailable.',
            ], 500);
        }

        $validatedData['role_id'] = $roleId;
        $validatedData['password'] = Hash::make($validatedData['password']);

        $user = User::create($validatedData);
        $user->load('role');

        $accessToken = $user->createToken('auth_token')->plainTextToken;
        RateLimiter::clear($rateLimitKey);

        return response()->json([
            'user' => $user,
            'access_token' => $accessToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'nullable|boolean',
        ]);

        $normalizedEmail = Str::lower(trim($validated['email']));
        $rateLimitKey = 'login|' . hash('sha256', $normalizedEmail . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json([
                'error' => 'Too many login attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($rateLimitKey),
            ], 429);
        }

        $credentials = [
            'email' => $normalizedEmail,
            'password' => $validated['password'],
        ];

        try {
            if (!Auth::attempt($credentials, (bool) ($validated['remember'] ?? false))) {
                RateLimiter::hit($rateLimitKey, 60);
                return response()->json(['error' => 'Invalid credentials'], 401);
            }

            RateLimiter::clear($rateLimitKey);

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            $user = User::with('role')->where('email', $normalizedEmail)->firstOrFail();
            $accessToken = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'user' => $user,
                'access_token' => $accessToken,
            ]);
        } catch (\Throwable $e) {
            Log::error('Login failed', [
                'email' => $normalizedEmail,
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Login failed. Please try again later.',
            ], 500);
        }
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        try {
            Password::sendResetLink(['email' => $validated['email']]);

            return response()->json([
                'message' => 'Եթե այդ էլ․ հասցեով օգտատեր գոյություն ունի, գաղտնաբառի վերականգնման հղումը ուղարկվել է։',
            ]);
        } catch (\Throwable $e) {
            Log::error('Password reset link failed', [
                'email' => $validated['email'],
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Չհաջողվեց ուղարկել վերականգնման հղումը։ Խնդրում ենք կրկին փորձել։',
            ], 500);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Վերականգնման հղումը անվավեր է կամ ժամկետանց։',
            ], 422);
        }

        return response()->json([
            'message' => 'Գաղտնաբառը հաջողությամբ փոխվել է։ Այժմ կարող եք մուտք գործել։',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user?->currentAccessToken();

        if ($currentToken && method_exists($currentToken, 'delete')) {
            $currentToken->delete();
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'User logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user->load(['role', 'permissions', 'role.permissions', 'factory']);

        if ($user->role && $user->role->name === 'admin') {
            $permissions = Permission::pluck('slug');
        } else {
            $userPermissions = $user->permissions->pluck('slug');
            $rolePermissions = $user->role
                ? $user->role->permissions()->pluck('slug')
                : collect();

            $permissions = $userPermissions
                ->merge($rolePermissions)
                ->unique()
                ->values();
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
            ] : null,
            'factory_id' => $user->factory_id,
            'factory' => $user->factory ? [
                'id' => $user->factory->id,
                'name' => $user->factory->name,
            ] : null,
            'permissions' => $permissions,
        ], 200);
    }
}
