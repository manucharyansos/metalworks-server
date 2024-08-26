<?php

namespace App\Http\Controllers\Api\Materials;

use App\Http\Controllers\Controller;
use App\Models\Materials;
use Illuminate\Http\Request;

class MaterialsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $materials = Materials::all();
        return response()->json(['materials' => $materials]);
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
//        $request->validate([
//            'name' => 'required|string',
//            'title' => 'required|string',
//            'size' => 'required|string',
//            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
//        ]);
//
//        $materials = new Materials();
//        $materials->name = $request->name;
//        $materials->title = $request->title;
//        $materials->size = $request->size;
//
//        if ($request->hasFile('image')) {
//            $image = $request->file('image');
//            $imageName = time() . '.' . $image->getClientOriginalExtension();
//            $image->move(public_path('materials-images'), $imageName);
//            $materials->image = $imageName;
//        }
//        $materials->save();
//
//        return response()->json([
//            'message' => 'Materials created successfully',
//            'materials' => $materials,
//        ], 201);

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'title' => 'required|string',
            'size' => 'required|string',
            'image' => 'required|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
        ]);

        $imageName = time() . '-' . $request->file('image')->getClientOriginalName();
        $request->file('image')->move(public_path('materials-images'), $imageName);

        $materials = Materials::create([
            'name' => $validatedData['name'],
            'title' => $validatedData['title'],
            'size' => $validatedData['size'],
            'image' => 'materials-images/' . $imageName,
        ]);

        $materials->save();

        return response()->json([
            'message' => 'Materials created successfully',
            'materials' => $materials,
        ], 201);
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
        $material = Materials::find($id);
        if (!$material) {
            return response()->json(['error' => 'Material not found.'], 404);
        }
        return response()->json($material);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $materials = Materials::find($id);
        if (!$materials) {
            return response()->json(['error' => 'Material not found.'], 404);
        }

        $request->validate([
            'name' => 'required|string',
            'title' => 'required|string',
            'size' => 'required|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $materials->name = $request->name;
        $materials->title = $request->title;
        $materials->size = $request->size;

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $image->move(public_path('materials-images'), $imageName);
            $materials->image = $imageName;
        }

        $materials->save();

        return response()->json([
            'message' => 'Materials updated successfully',
            'materials' => $materials,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $material = Materials::find($id);
        $material->delete();
        return response()->json('Material deleted successfully', 204);
    }
}
