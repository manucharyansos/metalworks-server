<?php

namespace App\Http\Controllers\Api\Factory;

use App\Http\Controllers\Controller;
use App\Models\FactoryFile;
use App\Models\FileExtension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    public function getFilesForFactoryAndOrder($factoryId, $orderId): JsonResponse
    {
        // Ստուգել, եթե տվյալ ֆայլերը կան տվյալ գործարանից ու պատվերից
        $files = FactoryFile::with('factory', 'order')
            ->where('factory_id', $factoryId)
            ->where('order_id', $orderId)
            ->get();

        // Վերադարձնել ֆայլերը JSON ձևաչափով
        return response()->json(['files' => $files], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'nullable|file|mimes:jpg,png,pdf,dxf',  // Ստուգում ենք, թե արդյոք դա ֆայլ է
            'factory_id' => 'required|exists:factories,id',
            'order_id' => 'required|exists:orders,id',
        ]);

        // Աշխատանքներ բեռնելու համար
        foreach ($request->file('files') as $index => $file) {
            // Եթե դա ֆիզիկական ֆայլ է, պահպանեք այն
            if ($file instanceof \Illuminate\Http\UploadedFile) {
                $path = $file->store('uploads/orders/' . $request->order_id);  // Ստեղծում ենք ֆայլի պահեստի հղումը
                $originalName = $file->getClientOriginalName();
            } else {
                // Եթե դա միայն path-ով ֆայլ է, ապա վերցնում ենք միայն տվյալները
                $path = $request->input('files')[$index];
                $originalName = $request->input('original_name')[$index];
            }

            // Նոր FactoryFile մոդելի գրառման ստեղծում
            FactoryFile::create([
                'factory_id' => $request->factory_id,
                'order_id' => $request->order_id,
                'path' => $path,
                'original_name' => $originalName,
            ]);
        }

        return response()->json(['message' => 'Files uploaded successfully']);
    }





    /**
     * Display the specified resource.
     */
    public function show(string $id)
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
