<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{CompanyMembership, CompanyPosition, Factory, Role, User};
use App\Support\{CompanyContext, MembershipAssignments};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The company's org chart: positions with their subordinate positions, and the
 * employees who hold them. A position gives its holders one access level
 * (role) and, for production work, a workshop, so placing an employee in a
 * position changes what they can open the same way the position editor does.
 */
class OrgChartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->chart($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        CompanyPosition::create($data + ['sort_order' => $this->nextOrder($data['parent_id'] ?? null)]);
        return response()->json($this->chart($request), 201);
    }

    public function update(Request $request, CompanyPosition $position): JsonResponse
    {
        $this->guardPosition($request, $position);
        $data = $this->validated($request, $position);
        DB::transaction(function () use ($request, $position, $data): void {
            $access = (int) $position->role_id !== (int) $data['role_id']
                || (int) ($position->factory_id ?? 0) !== (int) ($data['factory_id'] ?? 0);
            if ((int) ($position->parent_id ?? 0) !== (int) ($data['parent_id'] ?? 0)) {
                $data['sort_order'] = $this->nextOrder($data['parent_id'] ?? null);
            }
            $position->update($data);
            if (!$access) return;
            // Holders keep the position and get its new access level.
            foreach ($this->holders($position) as $membership) {
                $this->guardMember($request, $membership);
                $this->change($membership, fn (array $rows) => array_map(fn ($row) => (int) $row['position_id'] === (int) $position->id
                    ? ['role_id' => (int) $position->role_id, 'factory_id' => $position->factory_id ? (int) $position->factory_id : null] + $row
                    : $row, $rows));
            }
        });
        return response()->json($this->chart($request));
    }

    public function destroy(Request $request, CompanyPosition $position): JsonResponse
    {
        $this->guardPosition($request, $position);
        if ($position->assignments()->exists()) {
            throw ValidationException::withMessages(['position' => ['Նախ տեղափոխեք այս հաստիքի աշխատակիցներին։']]);
        }
        DB::transaction(function () use ($position): void {
            // Subordinate positions move up to the removed position's place.
            CompanyPosition::where('parent_id', $position->id)->update(['parent_id' => $position->parent_id]);
            $position->delete();
        });
        return response()->json($this->chart($request));
    }

    /** Puts an employee in a position, as an extra position or instead of one they hold. */
    public function place(Request $request, CompanyPosition $position): JsonResponse
    {
        $this->guardPosition($request, $position);
        $data = $request->validate([
            'user_id' => 'required|integer',
            'replace_assignment_id' => 'nullable|integer',
        ]);
        $membership = $this->member($request, (int) $data['user_id']);
        $replace = $data['replace_assignment_id'] ?? null;

        DB::transaction(function () use ($membership, $position, $replace): void {
            $this->change($membership, function (array $rows) use ($position, $replace): array {
                if (collect($rows)->contains(fn ($row) => (int) $row['position_id'] === (int) $position->id)) {
                    // Already here: moving from another position only drops that one.
                    return $replace ? array_values(array_filter($rows, fn ($row) => (int) $row['id'] !== (int) $replace)) : $rows;
                }
                $index = $replace ? collect($rows)->search(fn ($row) => (int) $row['id'] === (int) $replace) : false;
                if ($replace && $index === false) {
                    throw ValidationException::withMessages(['replace_assignment_id' => ['Աշխատակիցն այլևս չունի այդ հաստիքը։ Թարմացրեք էջը։']]);
                }
                $target = ['role_id' => (int) $position->role_id, 'factory_id' => $position->factory_id ? (int) $position->factory_id : null, 'position_id' => (int) $position->id];
                // The same access held through another assignment: use that one.
                $same = collect($rows)->search(fn ($row, $i) => $i !== $index
                    && (int) $row['role_id'] === $target['role_id'] && (int) ($row['factory_id'] ?? 0) === (int) ($target['factory_id'] ?? 0));
                if ($same !== false) {
                    if ($rows[$same]['position_id']) {
                        $name = CompanyPosition::whereKey($rows[$same]['position_id'])->value('name');
                        throw ValidationException::withMessages(['position' => [sprintf('Աշխատակիցն այս մուտքի մակարդակն արդեն ունի «%s» հաստիքում։ Տեղափոխեք նրան այնտեղից։', $name)]]);
                    }
                    $rows[$same]['position_id'] = $target['position_id'];
                    if ($index !== false) unset($rows[$index]);
                    return array_values($rows);
                }
                if ($index !== false) $rows[$index] = $target + ['id' => $rows[$index]['id']];
                else $rows[] = $target + ['id' => null];
                return $rows;
            });
        });
        return response()->json($this->chart($request));
    }

    public function remove(Request $request, CompanyPosition $position, User $user): JsonResponse
    {
        $this->guardPosition($request, $position);
        $membership = $this->member($request, $user->id);
        DB::transaction(function () use ($membership, $position): void {
            $this->change($membership, function (array $rows) use ($position): array {
                $left = array_values(array_filter($rows, fn ($row) => (int) $row['position_id'] !== (int) $position->id));
                if (count($left) === count($rows)) return $rows;
                if (!$left) {
                    throw ValidationException::withMessages(['position' => ['Աշխատակիցը պետք է ունենա առնվազն մեկ հաստիք։ Տեղափոխեք նրան այլ հաստիք։']]);
                }
                return $left;
            });
        });
        return response()->json($this->chart($request));
    }

    /**
     * Starts an empty chart from the positions employees already hold: the
     * administrator over the manager, and engineers and each workshop's
     * operators under the manager. Nobody's access changes.
     */
    public function generate(Request $request): JsonResponse
    {
        if (CompanyPosition::query()->exists()) {
            throw ValidationException::withMessages(['positions' => ['Կառուցվածքն արդեն ստեղծված է։']]);
        }
        DB::transaction(function (): void {
            $assignments = $this->staffMemberships()->flatMap->assignments
                ->filter(fn ($row) => in_array($row->role?->name, MembershipAssignments::STAFF_ROLES, true));
            $created = [];
            $make = function (Role $role, ?int $factoryId, ?int $parentId) use (&$created): CompanyPosition {
                $key = $role->id . ':' . ($factoryId ?? 0);
                if (isset($created[$key])) return $created[$key];
                // Production positions are named by their workshop; the rest by the access level.
                $name = $factoryId ? Factory::whereKey($factoryId)->value('name') . ' · օպերատոր' : ($role->value ?: $role->name);
                return $created[$key] = CompanyPosition::create([
                    'name' => $name, 'role_id' => $role->id, 'factory_id' => $factoryId,
                    'parent_id' => $parentId, 'sort_order' => count($created),
                ]);
            };
            $roles = Role::whereIn('name', ['admin', 'manager', 'engineer'])->get()->keyBy('name');
            $held = $assignments->pluck('role.name')->unique();
            $top = $held->contains('admin') ? $make($roles['admin'], null, null) : null;
            $head = $held->contains('manager') ? $make($roles['manager'], null, $top?->id) : $top;
            if ($held->contains('engineer')) $make($roles['engineer'], null, $head?->id);
            foreach ($assignments->sortBy(fn ($row) => [$row->factory?->name, $row->role?->name])->values() as $row) {
                if (!in_array($row->role->name, MembershipAssignments::OPERATORS, true) || !$row->factory_id) continue;
                $make($row->role, (int) $row->factory_id, $head?->id);
            }
            foreach ($assignments as $row) {
                $position = $created[$row->role_id . ':' . ($row->factory_id ?? 0)] ?? null;
                if ($position) $row->forceFill(['position_id' => $position->id])->save();
            }
        });
        return response()->json($this->chart($request), 201);
    }

    private function chart(Request $request): array
    {
        $positions = CompanyPosition::with(['role:id,name,value', 'factory:id,name'])
            ->orderBy('sort_order')->orderBy('id')->get();
        $memberships = $this->staffMemberships();
        $users = User::with('worker:id,user_id,last_name')
            ->whereIn('id', $memberships->pluck('user_id'))->get()->keyBy('id');
        $held = [];
        $unplaced = [];
        foreach ($memberships as $membership) {
            $user = $users->get($membership->user_id);
            if (!$user) continue;
            $lastName = $user->last_name ?: $user->worker?->last_name;
            $person = [
                'user_id' => $user->id,
                'name' => trim(implode(' ', array_filter([$user->name, $lastName]))) ?: $user->name,
                'email' => $user->email,
                'locked' => $this->locked($request, $user, $membership),
            ];
            foreach ($membership->assignments as $row) {
                if (!in_array($row->role?->name, MembershipAssignments::STAFF_ROLES, true)) continue;
                $entry = $person + [
                    'assignment_id' => $row->id,
                    'role' => $row->role->only(['id', 'name', 'value']),
                    'factory' => $row->factory?->only(['id', 'name']),
                ];
                if ($row->position_id) $held[$row->position_id][] = $entry;
                else $unplaced[] = $entry;
            }
        }

        return [
            'positions' => $positions->map(fn (CompanyPosition $position) => [
                'id' => $position->id,
                'parent_id' => $position->parent_id,
                'name' => $position->name,
                'description' => $position->description,
                'sort_order' => $position->sort_order,
                'role' => $position->role?->only(['id', 'name', 'value']),
                'factory' => $position->factory?->only(['id', 'name']),
                'members' => array_values(collect($held[$position->id] ?? [])->sortBy('name')->all()),
            ])->values(),
            'unplaced' => array_values(collect($unplaced)->sortBy('name')->all()),
            'roles' => Role::whereIn('name', $this->allowedRoles($request))->orderBy('id')->get(['id', 'name', 'value']),
            'factories' => Factory::activeWorkshops()->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function validated(Request $request, ?CompanyPosition $position = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'parent_id' => ['nullable', 'integer', Rule::exists('company_positions', 'id')->where('company_id', app(CompanyContext::class)->id())],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereIn('name', $this->allowedRoles($request)))],
            'factory_id' => 'nullable|integer',
            'description' => 'nullable|string|max:2000',
        ]);
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') throw ValidationException::withMessages(['name' => ['Նշեք հաստիքի անվանումը։']]);
        $role = Role::findOrFail($data['role_id']);
        $production = in_array($role->name, MembershipAssignments::OPERATORS, true);
        if ($production && (empty($data['factory_id']) || !Factory::activeWorkshops()->whereKey($data['factory_id'])->exists())) {
            throw ValidationException::withMessages(['factory_id' => ['Ընտրեք այս հաստիքի արտադրամասը։']]);
        }
        $data['factory_id'] = $production ? (int) $data['factory_id'] : null;
        $data['parent_id'] = empty($data['parent_id']) ? null : (int) $data['parent_id'];
        if ($position && $data['parent_id'] && in_array($data['parent_id'], $this->branch($position), true)) {
            throw ValidationException::withMessages(['parent_id' => ['Հաստիքը չի կարող ենթակա լինել ինքն իրեն կամ իր ենթականերից մեկին։']]);
        }
        return $data;
    }

    /** The position and every position under it. */
    private function branch(CompanyPosition $position): array
    {
        $children = CompanyPosition::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [];
        $queue = [(int) $position->id];
        while ($queue) {
            $id = array_shift($queue);
            if (in_array($id, $ids, true)) continue;
            $ids[] = $id;
            foreach ($children->get($id, collect()) as $child) $queue[] = (int) $child->id;
        }
        return $ids;
    }

    private function nextOrder(?int $parentId): int
    {
        return (int) CompanyPosition::where('parent_id', $parentId)->max('sort_order') + 1;
    }

    /** Applies a change to a membership's assignments through the shared rules. */
    private function change(CompanyMembership $membership, callable $change): void
    {
        $rows = array_map(fn (array $row) => [
            'id' => $row['id'],
            'role_id' => (int) $row['role_id'],
            'factory_id' => $row['factory_id'] ? (int) $row['factory_id'] : null,
            'position_id' => $row['position_id'] ? (int) $row['position_id'] : null,
        ], MembershipAssignments::rows($membership));
        $next = array_values($change($rows));
        if ($next === $rows) return;
        $seen = [];
        foreach ($next as $row) {
            $key = $row['role_id'] . ':' . ($row['factory_id'] ?? 0);
            if (isset($seen[$key])) throw ValidationException::withMessages(['position' => ['Աշխատակիցն արդեն ունի այս մուտքի մակարդակը։']]);
            $seen[$key] = true;
        }
        MembershipAssignments::sync($membership, array_map(fn ($row) => [
            'role_id' => $row['role_id'], 'factory_id' => $row['factory_id'], 'position_id' => $row['position_id'],
        ], $next));
    }

    /** @return Collection<int, CompanyMembership> */
    private function staffMemberships(): Collection
    {
        return CompanyMembership::where('company_id', app(CompanyContext::class)->id())
            ->where('is_active', true)
            ->with(['assignments.role:id,name,value', 'assignments.factory:id,name'])
            ->get()
            ->filter(fn ($membership) => $membership->assignments
                ->contains(fn ($row) => in_array($row->role?->name, MembershipAssignments::STAFF_ROLES, true)))
            ->values();
    }

    private function holders(CompanyPosition $position): Collection
    {
        $ids = $position->assignments()->pluck('membership_id')->unique();
        return CompanyMembership::whereIn('id', $ids)->get();
    }

    private function member(Request $request, int $userId): CompanyMembership
    {
        $membership = app(CompanyContext::class)->membership($userId);
        abort_unless($membership?->is_active && array_intersect(MembershipAssignments::roleNames($membership), MembershipAssignments::STAFF_ROLES), 404);
        $this->guardMember($request, $membership);
        return $membership;
    }

    /** Administrators are moved only by the platform administrator, as in the position editor. */
    private function guardMember(Request $request, CompanyMembership $membership): void
    {
        $user = User::find($membership->user_id);
        abort_if(!$user || $this->locked($request, $user, $membership), 403, 'This administrator is managed by the platform administrator.');
    }

    private function locked(Request $request, User $user, CompanyMembership $membership): bool
    {
        return (bool) $user->is_platform_admin
            || (!$request->user()->is_platform_admin && in_array('admin', MembershipAssignments::roleNames($membership), true));
    }

    private function guardPosition(Request $request, CompanyPosition $position): void
    {
        abort_if(!$request->user()->is_platform_admin && $position->role?->name === 'admin', 403, 'This administrator position is managed by the platform administrator.');
    }

    private function allowedRoles(Request $request): array
    {
        return $request->user()->is_platform_admin
            ? MembershipAssignments::STAFF_ROLES
            : array_values(array_diff(MembershipAssignments::STAFF_ROLES, ['admin']));
    }
}
