<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Models\FactoryOrder;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'client', 'factory']);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'last_name' => $user->last_name ?: $user->client?->last_name,
                'patronymic' => $user->patronymic,
                'email' => $user->email,
                'email_verified' => (bool) $user->email_verified_at,
                'email_verified_at' => $user->email_verified_at,
                'phone' => $user->phone ?: $user->client?->phone,
                'address' => $user->address ?: $user->client?->address,
                'role' => $user->role ? [
                    'id' => $user->role->id,
                    'name' => $user->role->name,
                    'value' => $user->role->value,
                ] : null,
                'factory_id' => $user->factory_id,
                'factory' => $user->factory ? [
                    'id' => $user->factory->id,
                    'name' => $user->factory->name,
                ] : null,
                'client' => $user->client,
            ],
            'capabilities' => [
                'client_orders' => optional($user->role)->name === 'authenticatedUser' || (bool) $user->client,
                'factory_work' => !empty($user->factory_id),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['role', 'client']);
        $currentEmail = Str::lower(trim((string) $user->email));

        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email', $currentEmail))),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'patronymic' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'client' => ['nullable', 'array'],
            'client.phone' => ['nullable', 'string', 'max:50'],
            'client.address' => ['nullable', 'string', 'max:255'],
            'client.last_name' => ['nullable', 'string', 'max:255'],
            'client.second_phone' => ['nullable', 'string', 'max:50'],
            'client.company_name' => ['nullable', 'string', 'max:255'],
            'client.AVC' => ['nullable', 'integer'],
            'client.accountant' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['email'] !== $currentEmail) {
            return response()->json([
                'message' => 'Էլ․ փոստը փոխելու համար ուղարկեք և հաստատեք 6-նիշ կոդը։',
                'errors' => [
                    'email' => ['Էլ․ փոստի փոփոխությունը պահանջում է հաստատման կոդ։'],
                ],
            ], 422);
        }

        $legacyClient = is_array($validated['client'] ?? null) ? $validated['client'] : [];
        $lastName = $validated['last_name'] ?? ($legacyClient['last_name'] ?? null);
        $phone = $validated['phone'] ?? ($legacyClient['phone'] ?? null);
        $address = $validated['address'] ?? ($legacyClient['address'] ?? null);

        $user->update([
            'name' => trim($validated['name']),
            'last_name' => $this->nullableTrim($lastName),
            'patronymic' => $this->nullableTrim($validated['patronymic'] ?? null),
            'phone' => $this->nullableTrim($phone),
            'address' => $this->nullableTrim($address),
        ]);

        if ($user->client) {
            $clientData = [
                'name' => $user->name,
                'last_name' => $user->last_name,
                'phone' => $user->phone ?: $user->client->phone,
                'address' => $user->address,
            ];

            foreach (['second_phone', 'company_name', 'AVC', 'accountant'] as $key) {
                if (array_key_exists($key, $legacyClient)) {
                    $clientData[$key] = $legacyClient[$key];
                }
            }

            $user->client->update($clientData);
        } elseif (optional($user->role)->name === 'authenticatedUser' && $user->phone) {
            $user->client()->create([
                'name' => $user->name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'address' => $user->address,
                'type' => 'physPerson',
            ]);
        }

        return $this->show($request);
    }

    public function requestEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $email = Str::lower(trim((string) $request->input('email')));
        $request->merge(['email' => $email]);

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $rateKey = 'profile-email-code|' . $user->id . '|' . hash('sha256', $validated['email']);
        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            return response()->json([
                'message' => 'Կոդը շատ հաճախ է պահանջվել։ Փորձեք մի փոքր ուշ։',
                'retry_after' => RateLimiter::availableIn($rateKey),
            ], 429);
        }

        RateLimiter::hit($rateKey, 600);

        $code = (string) random_int(100000, 999999);
        $cacheKey = $this->emailVerificationCacheKey($user->id, $validated['email']);

        Cache::put($cacheKey, [
            'email' => $validated['email'],
            'code_hash' => Hash::make($code),
        ], now()->addMinutes(10));

        try {
            Mail::raw(
                "MetalWorks-ի էլ․ փոստի հաստատման կոդը՝ {$code}\n\nԿոդը վավեր է 10 րոպե։ Եթե դուք չեք կատարել այս գործողությունը, պարզապես անտեսեք նամակը։",
                function ($message) use ($validated): void {
                    $message->to($validated['email'])
                        ->subject('MetalWorks — էլ․ փոստի հաստատման կոդ');
                }
            );
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            report($e);

            return response()->json([
                'message' => 'Չհաջողվեց ուղարկել հաստատման կոդը։ Ստուգեք mail կարգավորումները և կրկին փորձեք։',
            ], 500);
        }

        return response()->json([
            'message' => '6-նիշ հաստատման կոդը ուղարկվել է նշված էլ․ փոստին։',
            'email' => $validated['email'],
            'expires_in' => 600,
        ]);
    }

    public function confirmEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $email = Str::lower(trim((string) $request->input('email')));
        $code = preg_replace('/\s+/', '', (string) $request->input('code'));
        $request->merge(['email' => $email, 'code' => $code]);

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'code' => ['required', 'digits:6'],
        ]);

        $attemptKey = 'profile-email-confirm|' . $user->id . '|' . hash('sha256', $validated['email']);
        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            return response()->json([
                'message' => 'Շատ սխալ փորձեր են կատարվել։ Մի փոքր ուշ նոր կոդ պահանջեք։',
                'retry_after' => RateLimiter::availableIn($attemptKey),
            ], 429);
        }

        $cacheKey = $this->emailVerificationCacheKey($user->id, $validated['email']);
        $pending = Cache::get($cacheKey);

        if (!is_array($pending) || empty($pending['code_hash'])) {
            return response()->json([
                'message' => 'Հաստատման կոդը ժամկետանց է կամ չի գտնվել։ Նոր կոդ պահանջեք։',
            ], 422);
        }

        if (!Hash::check($validated['code'], $pending['code_hash'])) {
            RateLimiter::hit($attemptKey, 600);

            return response()->json([
                'message' => 'Հաստատման կոդը սխալ է։',
                'errors' => ['code' => ['Սխալ հաստատման կոդ։']],
            ], 422);
        }

        RateLimiter::clear($attemptKey);
        Cache::forget($cacheKey);

        $emailChanged = Str::lower((string) $user->email) !== $validated['email'];
        $user->forceFill([
            'email' => $validated['email'],
            'email_verified_at' => now(),
        ])->save();

        return response()->json([
            'message' => $emailChanged
                ? 'Էլ․ փոստը հաջողությամբ փոխվեց և հաստատվեց։'
                : 'Էլ․ փոստը հաջողությամբ հաստատվեց։',
            'email' => $user->email,
            'email_verified' => true,
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Ներկա գաղտնաբառը սխալ է։',
                'errors' => [
                    'current_password' => ['Ներկա գաղտնաբառը սխալ է։'],
                ],
            ], 422);
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logoutOtherDevices($validated['current_password']);
        }

        $user->update([
            'password' => $validated['password'],
        ]);

        $user->tokens()->delete();

        return response()->json([
            'message' => 'Գաղտնաբառը հաջողությամբ փոխվել է։ Խնդրում ենք նորից մուտք գործել։',
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['role', 'client']);
        $isClient = optional($user->role)->name === 'authenticatedUser' || (bool) $user->client;

        abort_unless($isClient, 403, 'Forbidden');

        $scope = $request->query('scope', 'current');
        abort_unless(in_array($scope, ['current', 'history'], true), 422, 'Invalid scope');

        $historyStatuses = ['completed', 'canceled', 'cancelled'];

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory:id,name',
            ])
            ->latest('id');

        if ($scope === 'history') {
            $query->whereIn('status', $historyStatuses);
        } else {
            $query->whereNotIn('status', $historyStatuses);
        }

        return response()->json($query->paginate(10));
    }

    public function factoryWork(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->factory_id, 403, 'Forbidden');

        $scope = $request->query('scope', 'current');
        abort_unless(in_array($scope, ['current', 'history'], true), 422, 'Invalid scope');

        $historyStatuses = ['finished', 'completed', 'done', 'confirmed', 'canceled', 'cancelled'];

        $query = FactoryOrder::query()
            ->where('factory_id', $user->factory_id)
            ->where(function ($operatorQuery) use ($user) {
                $operatorQuery->whereNull('operator_id')
                    ->orWhere('operator_id', $user->id);
            })
            ->with([
                'factory:id,name',
                'order.orderNumber',
                'order.prefixCode',
                'order.dates',
                'order.client.user:id,name',
            ])
            ->latest('id');

        if ($scope === 'history') {
            $query->whereIn('status', $historyStatuses);
        } else {
            $query->whereNotIn('status', $historyStatuses);
        }

        return response()->json($query->paginate(10));
    }

    private function emailVerificationCacheKey(int $userId, string $email): string
    {
        return 'profile-email-verification:' . $userId . ':' . hash('sha256', Str::lower($email));
    }

    private function nullableTrim($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
