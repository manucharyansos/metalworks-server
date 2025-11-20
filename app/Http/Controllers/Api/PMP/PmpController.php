<?php

namespace App\Http\Controllers\Api\PMP;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\Pmp;
use App\Models\RemoteNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PmpController extends Controller
{
    /**
     * Display all PMP records with related data
     */
    public function index(): JsonResponse
    {
        $pmp = Pmp::with(['remoteNumber', 'files.factory'])->get();
        return response()->json(['pmp' => $pmp]);
    }

    /**
     * Store new PMP and optionally RemoteNumber
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group'              => 'required|string|size:3',
            'group_name'         => 'required|string',
            'admin_confirmation' => 'required|boolean',
            'remote_number'      => 'nullable|string|size:2',
            'remote_number_name' => 'nullable|string',
        ]);

        $group = str_pad($validated['group'], 3, '0', STR_PAD_LEFT);

        $pmp = Pmp::updateOrCreate(
            ['group' => $group],
            [
                'group_name'         => $validated['group_name'],
                'admin_confirmation' => $validated['admin_confirmation'],
            ]
        );

        // If new PMP created — create folders per Factory
        if ($pmp->wasRecentlyCreated) {
            foreach (Factory::all() as $factory) {
                $factoryName = str_replace(' ', '_', $factory->value);
                Storage::disk('public')->makeDirectory("MetalWorks/PMP_{$factoryName}");
            }
        }

        // Optional RemoteNumber attach
        if (!empty($validated['remote_number']) && !empty($validated['remote_number_name'])) {
            $remoteNumber = str_pad($validated['remote_number'], 2, '0', STR_PAD_LEFT);

            $exists = RemoteNumber::where('pmp_id', $pmp->id)
                ->where(fn($q) => $q
                    ->where('remote_number', $remoteNumber)
                    ->orWhere('remote_number_name', $validated['remote_number_name']))
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'This remote number or name already exists for this group.',
                ], 422);
            }

            RemoteNumber::create([
                'pmp_id'             => $pmp->id,
                'remote_number'      => $remoteNumber,
                'remote_number_name' => $validated['remote_number_name'],
            ]);
        }

        return response()->json($pmp->load(['remoteNumber', 'files.factory']), 201);
    }

    /**
     * Display a specific PMP by its RemoteNumber ID
     */
    public function show($id): JsonResponse
    {
        $remote = RemoteNumber::findOrFail($id);

        $pmp = Pmp::with([
            'remoteNumber' => fn($q) => $q->where('id', $remote->id),
            'files'        => fn($q) => $q
                ->where('remote_number_id', $remote->id)
                ->with('factory'),
        ])->findOrFail($remote->pmp_id);

        return response()->json(['pmp' => $pmp]);
    }

    /**
     * Update PMP group / name / admin confirmation
     */
    public function update(Request $request, $id): JsonResponse
    {
        $pmp = Pmp::findOrFail($id);

        $validated = $request->validate([
            'group'              => 'required|string|size:3',
            'group_name'         => 'required|string',
            'admin_confirmation' => 'required|boolean',
        ]);

        $pmp->update($validated);
        return response()->json(['message' => 'PMP updated successfully', 'pmp' => $pmp]);
    }

    /**
     * Add new RemoteNumber for a specific PMP
     */
    public function remoteNumber(Request $request, $id): JsonResponse
    {
        $pmp = Pmp::findOrFail($id);

        $validatedData = $request->validate([
            'group' => 'required|string|unique:pmps,group,' . $id,
            'group_name' => 'required|string',
            'remote_number' => 'required|string|unique:remote_numbers,remote_number,NULL,id,pmp_id,' . $pmp->id,
            'remote_number_name' => 'required|string|unique:remote_numbers,remote_number_name,NULL,id,pmp_id,' . $pmp->id,
        ]);

        $pmp->update([
            'group' => $validatedData['group'],
            'group_name' => $validatedData['group_name'],
        ]);

        RemoteNumber::create([
            'pmp_id' => $pmp->id,
            'remote_number' => $validatedData['remote_number'],
            'remote_number_name' => $validatedData['remote_number_name'],
        ]);

        return response()->json($pmp->load('remoteNumber'));
    }

    /**
     * Delete PMP
     */
    public function destroy($id): JsonResponse
    {
        $pmp = Pmp::findOrFail($id);
        $pmp->delete();
        return response()->json(['message' => 'PMP deleted successfully']);
    }

    /**
     * Check if PMP exists by group or name
     */
    public function checkGroup(Request $request): JsonResponse
    {
        $group = $request->input('group');
        if (!$group) {
            return response()->json(['error' => 'Group parameter is required'], 400);
        }

        $pmp = Pmp::with(['remoteNumber', 'files.factory'])
            ->where('group', str_pad($group, 3, '0', STR_PAD_LEFT))
            ->first();

        return response()->json(['exists' => (bool)$pmp, 'pmp' => $pmp]);
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

        return response()->json(['exists' => (bool)$pmp, 'pmp' => $pmp]);
    }

//    public function checkPmpByRemoteNumber(Request $request, $id): JsonResponse
//    {
//        $pmp = Pmp::with(['remoteNumber', 'files.factory'])->find($id);
//
//        return $pmp
//            ? response()->json(['exists' => true, 'pmp' => $pmp])
//            : response()->json(['exists' => false, 'message' => 'PMP not found.']);
//    }

    public function checkPmpByRemoteNumber(Request $request, $id): JsonResponse
    {
        // $id → RemoteNumber-ի ID-ն է
        $remoteNumber = RemoteNumber::find($id);

        if (! $remoteNumber) {
            return response()->json([
                'exists'  => false,
                'message' => 'Remote number not found.',
            ], 404);
        }

        $pmp = Pmp::with(['remoteNumber', 'files.factory'])
            ->find($remoteNumber->pmp_id);

        if (! $pmp) {
            return response()->json([
                'exists'  => false,
                'message' => 'PMP not found.',
            ], 404);
        }

        return response()->json([
            'exists' => true,
            'pmp'    => $pmp,
        ]);
    }


    /**
     * Suggest next free remote number (01..99)
     */
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

    public function showByRemoteNumber(string $id)
    {
        $remoteNumber = RemoteNumber::findOrFail($id);

        $pmp = Pmp::with([
            'remoteNumber' => fn($q) => $q->where('id', $remoteNumber->id),
            'files' => fn($q) => $q
                ->where('remote_number_id', $remoteNumber->id)
                ->with('factory'),
        ])->findOrFail($remoteNumber->pmp_id);

        return response()->json(['pmp' => $pmp]);
    }
}
