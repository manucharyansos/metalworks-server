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
    public function index(): JsonResponse
    {
        $pmp = Pmp::with('remoteNumber', 'files')->get();
        return response()->json(['pmp' => $pmp]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group'              => 'required|string|size:3',
            'group_name'         => 'required|string',
            'admin_confirmation' => 'required|boolean',
            'remote_number'      => 'sometimes|nullable|string|size:2',
            'remote_number_name' => 'sometimes|nullable|string',
        ]);

        $group = str_pad($validated['group'], 3, '0', STR_PAD_LEFT);

        $pmp = Pmp::firstOrCreate(
            ['group' => $group],
            [
                'group_name'         => $validated['group_name'],
                'admin_confirmation' => $validated['admin_confirmation'],
            ]
        );

        if ($pmp->wasRecentlyCreated) {
            $factories = Factory::all();
            foreach ($factories as $factory) {
                $factoryName   = str_replace(' ', '_', $factory->value);
                $directoryPath = "MetalWorks/PMP_{$factoryName}";
                Storage::disk('public')->makeDirectory($directoryPath);
            }
        } else {
            $pmp->update([
                'group_name'         => $validated['group_name'],
                'admin_confirmation' => $validated['admin_confirmation'],
            ]);
        }

        if (!empty($validated['remote_number']) && !empty($validated['remote_number_name'])) {
            $remoteNumber = str_pad($validated['remote_number'], 2, '0', STR_PAD_LEFT);

            $exists = RemoteNumber::where('pmp_id', $pmp->id)
                ->where(function ($q) use ($remoteNumber, $validated) {
                    $q->where('remote_number', $remoteNumber)
                        ->orWhere('remote_number_name', $validated['remote_number_name']);
                })->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'This remote number or name already exists for the selected group.',
                    'errors'  => [
                        'remote_number'      => ['Already taken for this group.'],
                        'remote_number_name' => ['Already taken for this group.'],
                    ],
                ], 422);
            }

            RemoteNumber::create([
                'pmp_id'             => $pmp->id,
                'remote_number'      => $remoteNumber,
                'remote_number_name' => $validated['remote_number_name'],
            ]);
        }

        return response()->json($pmp->load('remoteNumber'), 201);
    }

    // show($id) — RemoteNumber ID ըստ քո լոգիկայի
    public function show($id): JsonResponse
    {
        $remote = RemoteNumber::findOrFail($id);

        $pmp = Pmp::with([
            'remoteNumber' => fn($q) => $q->where('id', $remote->id),
            'files'        => fn($q) => $q->where('remote_number_id', $remote->id),
        ])->where('id', $remote->pmp_id)->first();

        if (!$pmp) {
            return response()->json(['error' => 'PMP not found.'], 404);
        }

        return response()->json(['pmp' => $pmp]);
    }

    public function showByRemoteNumber($id): JsonResponse
    {
        $remote = RemoteNumber::findOrFail($id);

        $pmp = Pmp::with([
            'remoteNumber' => fn($q) => $q->where('id', $remote->id),
            'files'        => fn($q) => $q->where('remote_number_id', $remote->id),
        ])->where('id', $remote->pmp_id)->first();

        if (!$pmp) {
            return response()->json(['error' => 'PMP not found.'], 404);
        }

        return response()->json(['pmp' => $pmp]);
    }

    public function remoteNumber(Request $request, $id): JsonResponse
    {
        $pmp = Pmp::findOrFail($id);

        $validated = $request->validate([
            'group'               => 'required|string|unique:pmps,group,' . $id,
            'group_name'          => 'required|string',
            'remote_number'       => 'required|string|size:2|unique:remote_numbers,remote_number,NULL,id,pmp_id,' . $pmp->id,
            'remote_number_name'  => 'required|string|unique:remote_numbers,remote_number_name,NULL,id,pmp_id,' . $pmp->id,
        ]);

        $pmp->update([
            'group'      => str_pad($validated['group'], 3, '0', STR_PAD_LEFT),
            'group_name' => $validated['group_name'],
        ]);

        RemoteNumber::create([
            'pmp_id'             => $pmp->id,
            'remote_number'      => str_pad($validated['remote_number'], 2, '0', STR_PAD_LEFT),
            'remote_number_name' => $validated['remote_number_name'],
        ]);

        return response()->json($pmp->load('remoteNumber'));
    }

    public function destroy($id): JsonResponse
    {
        $pmp = Pmp::findOrFail($id);
        $pmp->delete();
        return response()->json(['message' => 'Pmp deleted successfully']);
    }

    // Checks
    public function checkGroup(Request $request): JsonResponse
    {
        $group = $request->input('group');
        if (!$group) {
            return response()->json(['error' => 'Group parameter is required'], 400);
        }

        $pmp = Pmp::with(['remoteNumber', 'files'])
            ->where('group', str_pad($group, 3, '0', STR_PAD_LEFT))
            ->first();

        return $pmp
            ? response()->json(['exists' => true, 'pmp' => $pmp])
            : response()->json(['exists' => false]);
    }

    public function checkGroupName(Request $request): JsonResponse
    {
        $groupName = $request->input('group_name');
        if (!$groupName) {
            return response()->json(['error' => 'Group parameter is required'], 400);
        }

        $pmp = Pmp::with(['remoteNumber', 'files'])
            ->where('group_name', $groupName)
            ->first();

        return $pmp
            ? response()->json(['exists' => true, 'pmp' => $pmp])
            : response()->json(['exists' => false]);
    }

    public function checkPmpByRemoteNumber(Request $request, $id): JsonResponse
    {
        $pmp = Pmp::with(['remoteNumber', 'files'])->where('id', $id)->first();

        return $pmp
            ? response()->json(['exists' => true, 'pmp' => $pmp])
            : response()->json(['exists' => false, 'message' => 'PMP with the given ID not found.']);
    }

    // Օգնական՝ առաջարկել հաջորդ ազատ 01..99
    public function nextRemoteNumber($pmpId): JsonResponse
    {
        $pmp = Pmp::with('remoteNumber')->findOrFail($pmpId);
        $taken = $pmp->remoteNumber->pluck('remote_number')->toArray();

        for ($i = 1; $i <= 99; $i++) {
            $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            if (!in_array($num, $taken, true)) {
                return response()->json(['next' => $num]);
            }
        }
        return response()->json(['next' => null]);
    }
}
