<?php

namespace App\Http\Controllers\Api\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Admin staff directory. Customer accounts intentionally do not appear here.
     */
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with(['role', 'factory', 'worker'])
            ->whereDoesntHave('client')
            ->where(function ($query) {
                $query
                    ->whereDoesntHave('role')
                    ->orWhereHas('role', function ($roleQuery) {
                        $roleQuery->where('name', '!=', 'authenticatedUser');
                    });
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $users,
        ]);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        //
    }

    public function show(User $user): JsonResponse
    {
        $this->ensureStaffAccount($user);

        $user->load(['role', 'factory', 'worker']);

        return response()->json($user);
    }

    public function edit(string $id)
    {
        //
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'message' => 'User update is not implemented on this endpoint.',
        ], 501);
    }

    public function destroy(string $id)
    {
        //
    }

    private function ensureStaffAccount(User $user): void
    {
        $user->loadMissing(['role', 'client']);

        abort_if(
            $user->client !== null || $user->role?->name === 'authenticatedUser',
            404,
            'Staff account not found.'
        );
    }
}
