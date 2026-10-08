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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        return app(\App\Http\Controllers\Api\RegistrationRequestController::class)->store($request);
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
            $remember = (bool) ($validated['remember'] ?? false);

            if (!Auth::attempt($credentials, $remember)) {
                RateLimiter::hit($rateLimitKey, 60);
                return response()->json(['error' => 'Invalid credentials'], 401);
            }

            RateLimiter::clear($rateLimitKey);

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            $user = User::with('role')->where('email', $normalizedEmail)->firstOrFail();
            $response = ['user' => $user];

            if (!$request->hasSession()) {
                $tokenExpiresAt = $remember ? now()->addDays(30) : now()->addDay();
                $response['access_token'] = $user
                    ->createToken('auth_token', ['*'], $tokenExpiresAt)
                    ->plainTextToken;
            }

            return response()->json($response);
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

        $email = Str::lower(trim($validated['email']));

        try {
            Password::sendResetLink(['email' => $email]);

            return response()->json([
                'message' => 'Եթե այդ էլ․ հասցեով օգտատեր գոյություն ունի, գաղտնաբառի վերականգնման հղումը ուղարկվել է։',
            ]);
        } catch (\Throwable $e) {
            Log::error('Password reset link failed', [
                'email' => $email,
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
                'email' => Str::lower(trim($validated['email'])),
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

        if (app(\App\Support\CompanyContext::class)->id()) $user->load(['role', 'factory']);
        else $user->setRelation('role', Role::where('name', 'authenticatedUser')->first())->setRelation('factory', null)->setRelation('worker', null);
        $role = $user->role?->name;

        $context = app(\App\Support\CompanyContext::class);
        if ($context->id() && in_array($role, ['admin', 'manager'], true)) {
            $permissions = Permission::query()->orderBy('slug')->pluck('slug');
        } elseif ($context->id()) {
            $permissions = $user->permissions()->wherePivot('allowed', true)->pluck('slug')
                ->filter(fn ($slug) => \App\Support\PermissionScope::allows($role, $slug))->values();
        } else { $permissions = collect(); }
        $companies = \App\Models\Company::where('is_active', true);
        if (!$user->is_platform_admin) $companies->whereHas('memberships', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true));

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
            ] : null,
            'factory_id' => $context->id() ? $user->factory_id : null,
            'factory' => $user->factory ? [
                'id' => $user->factory->id,
                'name' => $user->factory->name,
            ] : null,
            'permissions' => $permissions,
            'last_name' => $user->last_name ?: $user->worker?->last_name,
            'is_platform_admin' => (bool) $user->is_platform_admin,
            'company' => $context->company()?->summary(),
            'companies' => $companies->orderBy('id')->get()->map(fn ($c) => $c->summary()),
        ], 200);
    }
}
