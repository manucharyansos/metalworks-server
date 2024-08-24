<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index()
    {
        return response()->json(Service::all());
    }

    public function show($id)
    {
        return response()->json([
            'message' => 'Admin details',
            'data' => null
        ]);
    }

    public function store(Request $request)
    {
        return response()->json([
            'message' => 'Admin created successfully',
            'data' => null
        ], 201);
    }

    public function update(Request $request, $id)
    {
        return response()->json([
            'message' => 'Admin updated successfully',
            'data' => null
        ]);
    }

    public function destroy($id)
    {
        return response()->json([
            'message' => 'Admin deleted successfully'
        ], 204);
    }
}
