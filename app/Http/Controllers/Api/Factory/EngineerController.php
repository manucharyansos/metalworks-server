<?php

namespace App\Http\Controllers\Api\Factory;

use App\Http\Controllers\Controller;
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

    /**
     * Store a newly created resource in storage.
     */
   public function store(Request $request)
   {
       $request->validate([
           'files' => 'required|array',
           'files.*' => 'file|mimes:jpg,png,pdf', // adjust mime types
       ]);

       $files = $request->file('files');
       foreach ($files as $file) {
           $path = $file->store('laser-files');
           LaserFile::create(['path' => $path, 'original_name' => $file->getClientOriginalName()]);
       }

       return response()->json(['message' => 'Laser files uploaded successfully!'], 200);
   }

   // app/Http/Controllers/BendFileController.php
   public function store(Request $request)
   {
       $request->validate([
           'files' => 'required|array',
           'files.*' => 'file|mimes:jpg,png,pdf', // adjust mime types
       ]);

       $files = $request->file('files');
       foreach ($files as $file) {
           $path = $file->store('bend-files');
           BendFile::create(['path' => $path, 'original_name' => $file->getClientOriginalName()]);
       }

       return response()->json(['message' => 'Bend files uploaded successfully!'], 200);
   }

   // app/Http/Controllers/OrderController.php
   public function store(Request $request)
   {
       $order = Order::create($request->all());

       foreach ($request->laserFiles as $file) {
           $order->files()->create(['path' => $file['path']]);
       }

       return response()->json(['message' => 'Order created successfully!'], 200);
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
