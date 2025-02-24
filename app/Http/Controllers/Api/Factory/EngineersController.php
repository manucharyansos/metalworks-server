<?php

namespace App\Http\Controllers\Api\Factory;

use App\Http\Controllers\Controller;
use App\Mail\OrderCreated;
use App\Models\FactoryFile;
use App\Models\FileExtension;
use App\Models\Order;
use App\Models\PrefixCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EngineersController extends Controller
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
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'nullable|file|mimes:jpg,png,pdf,dxf',
            'factory_id' => 'required|exists:factories,id',
            'order_id' => 'required|exists:orders,id',
        ]);

        foreach ($request->file('files') as $index => $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile) {
                $path = $file->store('uploads/orders/' . $request->order_id);
                $originalName = $file->getClientOriginalName();
            } else {
                $path = $request->input('files')[$index];
                $originalName = $request->input('original_name')[$index];
            }

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

    private function generateOrderNumber(): string
    {
        $currentMonth = date('m');
        $currentYear = date('Y');
        $sequenceNumber = Order::whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $currentMonth)
                ->count() + 1;

        return sprintf('%s-%s-%04d', $currentYear, $currentMonth, $sequenceNumber);
    }

    private function generateUniquePrefixCode(): string
    {
        $prefixCode = strtoupper(bin2hex(random_bytes(3)));

        while (PrefixCode::where('code', $prefixCode)->exists()) {
            $prefixCode = strtoupper(bin2hex(random_bytes(3)));
        }

        return $prefixCode;
    }
}
