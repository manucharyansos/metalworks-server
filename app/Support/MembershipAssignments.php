<?php

namespace App\Support;

use App\Models\{CompanyMembership, Factory, Role};
use Illuminate\Support\Facades\{DB, Schema, Validator};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MembershipAssignments
{
    public const STAFF_ROLES = ['admin', 'manager', 'engineer', 'laser', 'bend', 'powder_catting'];
    public const OPERATORS = ['laser', 'bend', 'powder_catting'];

    public static function validate(array $data, array $allowedRoles): array
    {
        // Retain compatibility with the deployed single-position client/API.
        if (!array_key_exists('assignments', $data)) {
            $legacy = Validator::make($data, [
                'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereIn('name', $allowedRoles))],
                'factory_id' => 'nullable|integer|exists:factories,id',
            ])->validate();
            $role = Role::findOrFail($legacy['role_id']);
            if (in_array($role->name, self::OPERATORS, true) && empty($legacy['factory_id'])) {
                throw ValidationException::withMessages(['factory_id' => ['Select a workshop for this position.']]);
            }
            return [['role_id' => (int) $role->id, 'factory_id' => in_array($role->name, self::OPERATORS, true) ? (int) $legacy['factory_id'] : null]];
        }
        $validated = Validator::make($data, [
            'assignments' => 'required|array|min:1|max:50',
            'assignments.*.role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereIn('name', $allowedRoles))],
            'assignments.*.factory_id' => 'nullable|integer|exists:factories,id',
        ])->validate();
        $roles = Role::whereIn('id', array_column($validated['assignments'], 'role_id'))->get()->keyBy('id');
        $seen = []; $result = [];
        foreach ($validated['assignments'] as $index => $row) {
            $role = $roles->get($row['role_id']);
            $operator = in_array($role->name, self::OPERATORS, true);
            if ($operator && empty($row['factory_id'])) throw ValidationException::withMessages(["assignments.$index.factory_id" => ['Select a workshop for this position.']]);
            if (!$operator && !empty($row['factory_id'])) throw ValidationException::withMessages(["assignments.$index.factory_id" => ['Workshops are assigned to production positions.']]);
            $factory = $operator ? (int) $row['factory_id'] : null;
            $key = $role->id . ':' . ($factory ?? 0);
            if (isset($seen[$key])) throw ValidationException::withMessages(["assignments.$index.role_id" => ['This position and workshop are already selected.']]);
            $seen[$key] = true; $result[] = ['role_id' => (int) $role->id, 'factory_id' => $factory];
        }
        if ($roles->contains('name', 'authenticatedUser') && count($result) !== 1) throw ValidationException::withMessages(['assignments' => ['Client access cannot be combined with employee positions in one company.']]);
        return $result;
    }

    public static function sync(CompanyMembership $membership, array $assignments, bool $resetPermissions = false): void
    {
        DB::transaction(function () use ($membership, $assignments, $resetPermissions): void {
            $membership = CompanyMembership::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $oldRoles = self::roleNames($membership);
            $primary = $assignments[0];
            $membership->fill($primary)->saveQuietly();
            $keep = [];
            foreach ($assignments as $row) {
                $assignment = $membership->assignments()->firstOrCreate($row, ['user_id' => $membership->user_id]);
                $keep[] = $assignment->id;
            }
            $membership->assignments()->whereNotIn('id', $keep)->delete();
            $membership->unsetRelation('assignments');
            $newRoles = self::roleNames($membership);
            if ($resetPermissions || !array_intersect($oldRoles, $newRoles)) $membership->permissions()->detach();
            else {
                $allowed = PermissionScope::forRoles($newRoles)['permissions'];
                $stale = $membership->permissions()->whereNotIn('slug', $allowed)->pluck('permissions.id')->all();
                if ($stale) $membership->permissions()->detach($stale);
            }
            app(CompanyContext::class)->forgetMembership($membership->user_id);
        }, 3);
    }

    public static function roleNames(?CompanyMembership $membership): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($row) => $row['role']['name'] ?? null, self::rows($membership)))));
    }

    public static function rows(?CompanyMembership $membership): array
    {
        if (!$membership) return [];
        if (!Schema::hasTable('membership_assignments')) {
            return [['id' => null, 'role_id' => $membership->role_id, 'factory_id' => $membership->factory_id,
                'role' => $membership->role?->only(['id', 'name', 'value']),
                'factory' => $membership->factory_id ? Factory::find($membership->factory_id)?->only(['id', 'name']) : null, 'is_primary' => true]];
        }
        return $membership->assignments()->with(['role', 'factory'])->orderBy('id')->get()->map(fn ($row) => [
            'id' => $row->id, 'role_id' => $row->role_id, 'factory_id' => $row->factory_id,
            'role' => $row->role?->only(['id', 'name', 'value']), 'factory' => $row->factory?->only(['id', 'name']),
            'is_primary' => (int) $row->role_id === (int) $membership->role_id && (int) $row->factory_id === (int) $membership->factory_id,
        ])->sortByDesc('is_primary')->values()->all();
    }
}
