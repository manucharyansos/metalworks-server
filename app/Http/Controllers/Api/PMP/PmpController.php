<?php

namespace App\Http\Controllers\Api\PMP;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\Pmp;
use App\Models\RemoteNumber;
use App\Support\ProjectRemoval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PmpController extends Controller
{
    public function index(): JsonResponse
    {
        $pmp = Pmp::with(['remoteNumber', 'files.factory'])->get();
        return response()->json(['pmp' => $pmp]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group' => 'required|string|size:3',
            'group_name' => 'required|string|max:255',
            'admin_confirmation' => 'required|boolean',
            'remote_number' => 'nullable|string|size:2',
            'remote_number_name' => 'nullable|string|max:255',
        ]);

        $group = str_pad($validated['group'], 3, '0', STR_PAD_LEFT);
        $remoteNumber = !empty($validated['remote_number'])
            ? str_pad($validated['remote_number'], 2, '0', STR_PAD_LEFT)
            : null;
        $remoteName = $validated['remote_number_name'] ?? null;

        $existingPmp = Pmp::where('group', $group)->first();
        if ($existingPmp && $remoteNumber && $remoteName) {
            $exists = RemoteNumber::where('pmp_id', $existingPmp->id)
                ->where(function ($query) use ($remoteNumber, $remoteName) {
                    $query->where('remote_number', $remoteNumber)
                        ->orWhere('remote_number_name', $remoteName);
                })
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'This remote number or name already exists for this group.',
                ], 422);
            }
        }

        $wasCreated = false;
        $pmp = DB::transaction(function () use (
            $group,
            $validated,
            $remoteNumber,
            $remoteName,
            &$wasCreated
        ) {
            $pmp = Pmp::updateOrCreate(
                ['group' => $group],
                [
                    'group_name' => $validated['group_name'],
                    'admin_confirmation' => $validated['admin_confirmation'],
                ]
            );

            $wasCreated = $pmp->wasRecentlyCreated;

            if ($remoteNumber && $remoteName) {
                RemoteNumber::create([
                    'pmp_id' => $pmp->id,
                    'remote_number' => $remoteNumber,
                    'remote_number_name' => $remoteName,
                ]);
            }

            return $pmp;
        });

        if ($wasCreated) {
            foreach (Factory::all() as $factory) {
                $factoryName = str_replace(' ', '_', $factory->value);
                Storage::disk('private')->makeDirectory("companies/" . app(\App\Support\CompanyContext::class)->id() . "/PMP_{$factoryName}");
            }
        }

        return response()->json($pmp->load(['remoteNumber', 'files.factory']), 201);
    }

    public function show($id): JsonResponse
    {
        $remote = RemoteNumber::findOrFail($id);

        $pmp = Pmp::with([
            'remoteNumber' => fn ($q) => $q->where('id', $remote->id),
            'files' => fn ($q) => $q
                ->where('remote_number_id', $remote->id)
                ->with('factory'),
        ])->findOrFail($remote->pmp_id);

        return response()->json(['pmp' => $pmp]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $pmp = Pmp::findOrFail($id);

        $validated = $request->validate([
            'group' => [
                'required',
                'string',
                'size:3',
                Rule::unique('pmps', 'group')->ignore($pmp->id),
            ],
            'group_name' => 'required|string|max:255',
            'admin_confirmation' => 'required|boolean',
        ]);

        $pmp->update($validated);
        return response()->json(['message' => 'Project updated successfully', 'pmp' => $pmp]);
    }

    public function remoteNumber(Request $request, $id): JsonResponse
    {
        $pmp = Pmp::findOrFail($id);

        $validatedData = $request->validate([
            'group' => 'required|string|unique:pmps,group,' . $id,
            'group_name' => 'required|string|max:255',
            'remote_number' => 'required|string|unique:remote_numbers,remote_number,NULL,id,pmp_id,' . $pmp->id,
            'remote_number_name' => 'required|string|max:255|unique:remote_numbers,remote_number_name,NULL,id,pmp_id,' . $pmp->id,
        ]);

        DB::transaction(function () use ($pmp, $validatedData) {
            $pmp->update([
                'group' => $validatedData['group'],
                'group_name' => $validatedData['group_name'],
            ]);

            RemoteNumber::create([
                'pmp_id' => $pmp->id,
                'remote_number' => $validatedData['remote_number'],
                'remote_number_name' => $validatedData['remote_number_name'],
            ]);
        });

        return response()->json($pmp->load('remoteNumber'));
    }

    public function destroy($id): JsonResponse
    {
        // A plain delete cascaded into tasks' file lists and left the files on disk.
        ProjectRemoval::deleteGroup(Pmp::findOrFail($id));
        return response()->json(['message' => 'Project deleted successfully']);
    }

    /** Renames the opened subgroup and its group; the codes stay, since stored files are filed under them. */
    public function renameSubgroup(Request $request, string $id): JsonResponse
    {
        $remote = RemoteNumber::findOrFail($id);
        $request->merge([
            'group_name' => trim((string) $request->input('group_name')),
            'remote_number_name' => trim((string) $request->input('remote_number_name')),
        ]);
        $data = $request->validate([
            'group_name' => 'required|string|max:255',
            'remote_number_name' => 'required|string|max:255',
        ]);

        DB::transaction(function () use ($remote, $data) {
            $pmp = Pmp::whereKey($remote->pmp_id)->lockForUpdate()->firstOrFail();
            $remote = RemoteNumber::whereKey($remote->id)->lockForUpdate()->firstOrFail();
            // Names are picked by typing them, so two groups (or two subgroups of one
            // group) cannot share one. An unchanged name is never re-checked.
            if ($pmp->group_name !== $data['group_name']) {
                $taken = Pmp::whereKeyNot($pmp->id)->where('group_name', $data['group_name'])->value('group');
                if ($taken !== null) throw ValidationException::withMessages(['group_name' => [sprintf('«%s» անունով խումբ արդեն կա (%s)։', $data['group_name'], $taken)]]);
                $pmp->update(['group_name' => $data['group_name']]);
            }
            if ($remote->remote_number_name !== $data['remote_number_name']) {
                $taken = RemoteNumber::where('pmp_id', $pmp->id)->whereKeyNot($remote->id)->where('remote_number_name', $data['remote_number_name'])->value('remote_number');
                if ($taken !== null) throw ValidationException::withMessages(['remote_number_name' => [sprintf('Այս խմբում «%s» անունով ենթախումբ արդեն կա (%s)։', $data['remote_number_name'], $taken)]]);
                $remote->update(['remote_number_name' => $data['remote_number_name']]);
            }
        }, 3);

        return $this->showByRemoteNumber($id);
    }

    /** What deleting the subgroup would remove, or which tasks keep it. */
    public function subgroupDeletion(string $id): JsonResponse
    {
        return response()->json(ProjectRemoval::preview(RemoteNumber::findOrFail($id)));
    }

    public function destroySubgroup(string $id): JsonResponse
    {
        $result = ProjectRemoval::deleteSubgroup(RemoteNumber::findOrFail($id));
        return response()->json(['message' => 'Ենթախումբը ջնջված է։'] + $result);
    }

    public function checkGroup(Request $request): JsonResponse
    {
        $group = $request->input('group');
        if (!$group) {
            return response()->json(['error' => 'Group parameter is required'], 400);
        }

        $pmp = Pmp::with(['remoteNumber', 'files.factory'])
            ->where('group', str_pad($group, 3, '0', STR_PAD_LEFT))
            ->first();

        return response()->json(['exists' => (bool) $pmp, 'pmp' => $pmp]);
    }

    public function checkGroupName(Request $request): JsonResponse
    {
        $name = $request->input('group_name');
        if (!$name) {
            return response()->json(['error' => 'Group name is required'], 400);
        }

        $pmp = Pmp::with(['remoteNumber', 'files.factory'])
            ->where('group_name', $name)
            ->first();

        return response()->json(['exists' => (bool) $pmp, 'pmp' => $pmp]);
    }

    public function checkPmpByRemoteNumber(Request $request, $id): JsonResponse
    {
        $remoteNumber = RemoteNumber::find($id);

        if (!$remoteNumber) {
            return response()->json([
                'exists' => false,
                'message' => 'Remote number not found.',
            ], 404);
        }

        $pmp = Pmp::with(['remoteNumber', 'files.factory'])
            ->find($remoteNumber->pmp_id);

        if (!$pmp) {
            return response()->json([
                'exists' => false,
                'message' => 'Project not found.',
            ], 404);
        }

        return response()->json([
            'exists' => true,
            'pmp' => $pmp,
        ]);
    }

    public function nextRemoteNumber($pmpId): JsonResponse
    {
        $pmp = Pmp::with('remoteNumber')->findOrFail($pmpId);
        $taken = $pmp->remoteNumber->pluck('remote_number')->toArray();

        for ($i = 1; $i <= 99; $i++) {
            $num = str_pad($i, 2, '0', STR_PAD_LEFT);
            if (!in_array($num, $taken, true)) {
                return response()->json(['next' => $num]);
            }
        }

        return response()->json(['next' => null]);
    }

    public function showByRemoteNumber(string $id): JsonResponse
    {
        $remoteNumber = RemoteNumber::findOrFail($id);

        $pmp = Pmp::with([
            'remoteNumber' => fn ($q) => $q->where('id', $remoteNumber->id),
            'files' => fn ($q) => $q
                ->where('remote_number_id', $remoteNumber->id)
                ->with('factory'),
        ])->findOrFail($remoteNumber->pmp_id);

        return response()->json(['pmp' => $pmp]);
    }
}
