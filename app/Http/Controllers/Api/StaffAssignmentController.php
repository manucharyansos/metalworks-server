<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Factory, Role, User};
use App\Support\{CompanyContext, MembershipAssignments};
use Illuminate\Http\{JsonResponse, Request};

class StaffAssignmentController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        $membership = $this->membership($user);
        return response()->json([
            'assignments' => MembershipAssignments::rows($membership),
            'roles' => Role::whereIn('name', $this->allowedRoles($request))->orderBy('name')->get(['id', 'name', 'value']),
            'factories' => Factory::orderBy('name')->get(['id', 'name']),
            'read_only' => $this->protected($request, $user),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $membership = $this->membership($user);
        abort_if($this->protected($request, $user), 403, 'This administrator is managed by the platform administrator.');
        $request->validate(['assignments' => 'required|array|min:1|max:50']);
        $assignments = MembershipAssignments::validate($request->all(), $this->allowedRoles($request));
        MembershipAssignments::sync($membership, $assignments);
        return response()->json(['assignments' => MembershipAssignments::rows($membership->fresh())]);
    }

    private function membership(User $user)
    {
        $membership = app(CompanyContext::class)->membership($user->id);
        abort_unless($membership?->is_active && array_intersect(MembershipAssignments::roleNames($membership), MembershipAssignments::STAFF_ROLES), 404);
        return $membership;
    }

    private function protected(Request $request, User $user): bool
    {
        return (bool) $user->is_platform_admin || (!$request->user()->is_platform_admin && in_array('admin', $user->workRoleNames(), true));
    }

    private function allowedRoles(Request $request): array
    {
        return $request->user()->is_platform_admin ? MembershipAssignments::STAFF_ROLES : array_values(array_diff(MembershipAssignments::STAFF_ROLES, ['admin']));
    }
}
