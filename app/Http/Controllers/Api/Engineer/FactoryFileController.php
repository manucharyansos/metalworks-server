<?php

namespace App\Http\Controllers\Api\Engineer;

use App\Http\Controllers\Controller;
use App\Models\FactoryFile;
use App\Models\FactoryOrder;
use Illuminate\Http\Request;

class FactoryFileController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store(Request $request)
    {
        $request->validate([
            'factory_order_id' => 'required|exists:factory_orders,id',
            'files' => 'required|array',
            'files.*' => 'file|mimes:dxf',
        ]);

        $factoryOrder = FactoryOrder::findOrFail($request->factory_order_id);

        $uploadedFiles = [];

        foreach ($request->file('files') as $file) {
            $path = $file->store('factory_files', 'public');
            $originalName = $file->getClientOriginalName();

            $factoryFile = FactoryFile::create([
                'factory_order_id' => $factoryOrder->id,
                'path' => $path,
                'original_name' => $originalName,
            ]);

            $uploadedFiles[] = $factoryFile;
        }

        return response()->json($uploadedFiles, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
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
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
