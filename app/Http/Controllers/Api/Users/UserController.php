<?php

namespace App\Http\Controllers\Api\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Admin staff directory. Customer, guest and role-less accounts
     * intentionally do not appear here.
     */
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with(['role', 'factory', 'worker'])
            ->forRoles(['authenticatedUser', 'guestUser'], true)
            ->orderBy('name')
            ->get();

        $users->each(fn ($user) => $user->setAttribute('assignments', $user->workAssignments()));
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

        $user->setAttribute('assignments', $user->workAssignments());
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
        abort_unless(app(\App\Support\CompanyContext::class)->membership($user->id)?->is_active, 404);
        $user->loadMissing(['role', 'client']);

        abort_if(
            !array_intersect($user->workRoleNames(), \App\Support\MembershipAssignments::STAFF_ROLES),
            404,
            'Staff account not found.'
        );
    }
}
