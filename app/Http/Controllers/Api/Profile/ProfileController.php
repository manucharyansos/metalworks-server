<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Models\FactoryOrder;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
                'email' => $user->email,
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
        $user = $request->user();

        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'current_password' => ['nullable', 'string'],
            'client' => ['nullable', 'array'],
            'client.phone' => ['nullable', 'string', 'max:50'],
            'client.address' => ['nullable', 'string', 'max:255'],
            'client.last_name' => ['nullable', 'string', 'max:255'],
            'client.second_phone' => ['nullable', 'string', 'max:50'],
            'client.company_name' => ['nullable', 'string', 'max:255'],
            'client.AVC' => ['nullable', 'integer'],
            'client.accountant' => ['nullable', 'string', 'max:255'],
        ]);

        $emailChanged = Str::lower((string) $user->email) !== $validated['email'];
        if ($emailChanged) {
            $currentPassword = $validated['current_password'] ?? '';
            if ($currentPassword === '' || !Hash::check($currentPassword, $user->password)) {
                return response()->json([
                    'message' => 'Էլ․ փոստը փոխելու համար հաստատեք ներկա գաղտնաբառը։',
                    'errors' => [
                        'current_password' => ['Ներկա գաղտնաբառը պարտադիր է և պետք է ճիշտ լինի։'],
                    ],
                ], 422);
            }
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        if (array_key_exists('client', $validated) && is_array($validated['client'])) {
            $clientData = array_filter(
                $validated['client'],
                static fn ($value) => $value !== null
            );

            if ($user->client) {
                $user->client->update($clientData);
            } elseif (
                optional($user->role)->name === 'authenticatedUser' &&
                !empty($clientData['phone'])
            ) {
                $user->client()->create(array_merge([
                    'name' => $user->name,
                    'phone' => $clientData['phone'],
                    'type' => 'individual',
                ], $clientData));
            }
        }

        return $this->show($request);
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

        $user->update([
            'password' => $validated['password'],
        ]);

        // Keep the current personal-access-token request alive long enough for the
        // frontend to perform its normal logout, while revoking all other API tokens.
        // Stateful Sanctum sessions may expose a transient token instead of a model,
        // so only call getKey() when that method actually exists.
        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken && method_exists($currentToken, 'getKey')
            ? $currentToken->getKey()
            : null;

        if ($currentTokenId) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        } else {
            $user->tokens()->delete();
        }

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
}
