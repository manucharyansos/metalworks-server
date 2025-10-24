<?php

namespace App\Http\Controllers\Api\PMP;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\FactoryOrder;
use App\Models\Pmp;
use App\Models\RemoteNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PmpController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $pmp = Pmp::with('remoteNumber', 'files')->get();
        return response()->json(['pmp' => $pmp]);
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'group' => 'required|string|size:3',
            'group_name' => 'required|string',
            'remote_number' => 'required|string',
            'remote_number_name' => 'required|string',
            'admin_confirmation' => 'required|boolean',
        ]);

        $pmp = Pmp::where('group', $validatedData['group'])->first();

        if (!$pmp) {
            $pmp = Pmp::create([
                'group' => $validatedData['group'],
                'group_name' => $validatedData['group_name'],
                'admin_confirmation' => $validatedData['admin_confirmation'],
            ]);

            $factories = Factory::all();
            foreach ($factories as $factory) {
                $factoryName = str_replace(' ', '_', $factory->value);
                $directoryPath = "MetalWorks/PMP_{$factoryName}";
                Storage::disk('public')->makeDirectory($directoryPath);
            }
        }

        RemoteNumber::create([
            'pmp_id' => $pmp->id,
            'remote_number' => $validatedData['remote_number'],
            'remote_number_name' => $validatedData['remote_number_name'],
        ]);

        return response()->json($pmp->load('remoteNumber'), 201);

    }

    public function uploadFiles(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'pmp_id' => 'required|exists:pmps,id',
                'factories' => 'required|array',
                'factories.*.id' => 'required|exists:factories,id',
                'factories.*.files' => 'required|array',
                'factories.*.files.*' => 'required|file|max:10240',
            ]);
            $pmp = Pmp::findOrFail($validatedData['pmp_id']);
            foreach ($validatedData['factories'] as $factoryData) {
                $factory = Factory::findOrFail($factoryData['id']);
                $factoryName = str_replace(' ', '_', $factory->value);
                $factoryOrder = FactoryOrder::firstOrCreate(
                    [
                        'pmp_id' => $pmp->id,
                        'factory_id' => $factory->id
                    ],
                );
                $directoryPath = "MetalWorks/PMP_/{$factoryName}";
                Storage::disk('public')->makeDirectory($directoryPath);
                foreach ($factoryData['files'] as $file) {
                    $originalName = $file->getClientOriginalName();
                    $fileName = pathinfo($originalName, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                    $path = $file->storeAs(
                        $directoryPath,
                        $fileName,
                        'public'
                    );

                    $factoryOrder->files()->create([
                        'path' => $path,
                        'original_name' => $originalName,
                    ]);
                }
            }

            return response()->json(['message' => 'Files uploaded successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id): JsonResponse
    {
        $remoteNumber = RemoteNumber::findOrFail($id);

        $pmp = Pmp::with([
            'remoteNumber' => function($query) use ($id) {
                $query->where('id', $id);
            },
            'files' => function($query) use ($id) {
                $query->where('remote_number_id', $id);
            }
        ])
        ->where('id', $remoteNumber->pmp_id)
        ->first();

        if (!$pmp) {
            return response()->json(['error' => 'PMP not found.'], 404);
        }

        return response()->json(['pmp' => $pmp]);
    }



    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        //
    }

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
     * Remove the specified resource from storage.
     */
    public function destroy($id): JsonResponse
    {
        $pmp = Pmp::findOrFail($id);
        $pmp->delete();

        return response()->json(['message' => 'Pmp deleted successfully']);
    }

    public function checkGroup(Request $request): JsonResponse
    {
        $group = $request->input('group');
        if (!$group) {
            return response()->json(['error' => 'Group parameter is required'], 400);
        }

        $pmp = Pmp::with(['remoteNumber', 'files'])
            ->where('group', $group)
            ->first();

        if ($pmp) {
            return response()->json([
                'exists' => true,
                'pmp' => $pmp,
            ]);
        } else {
            return response()->json(['exists' => false]);
        }
    }


    public function checkGroupName(Request $request): JsonResponse
    {
        $group = $request->input('group_name');
        if (!$group) {
            return response()->json(['error' => 'Group parameter is required'], 400);
        }

        $pmp = Pmp::with(['remoteNumber', 'files'])
            ->where('group_name', $group)
            ->first();

        if ($pmp) {
            return response()->json([
                'exists' => true,
                'pmp' => $pmp,
            ]);
        } else {
            return response()->json(['exists' => false]);
        }
    }

    public function checkPmpByRemoteNumber(Request $request, $id): JsonResponse
    {
       $pmp = Pmp::with(['remoteNumber', 'files'])
           ->where('id', $id)
           ->first();

       if ($pmp) {
           return response()->json([
               'exists' => true,
               'pmp' => $pmp,
           ]);
       }

       return response()->json([
           'exists' => false,
           'message' => 'PMP with the given ID not found.',
       ]);
    }
    }
