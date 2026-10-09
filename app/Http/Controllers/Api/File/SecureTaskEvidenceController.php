<?php

namespace App\Http\Controllers\Api\File;

use App\Http\Controllers\Controller;
use App\Models\FactoryOrder;
use App\Support\TaskAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SecureTaskEvidenceController extends Controller
{
    public function show(Request $request, FactoryOrder $factoryOrder)
    {
        $user = $request->user();
        // Customer task access does not grant access to internal completion proof.
        abort_unless($user && $user->role?->name !== 'authenticatedUser' && TaskAccess::canView($user, $factoryOrder->order), 403);
        if ($user->factory_id) abort_unless((int) $factoryOrder->factory_id === (int) $user->factory_id && (int) $factoryOrder->operator_id === (int) $user->id, 403);
        $path = $factoryOrder->evidence_photo_path;
        abort_unless($path && Storage::disk('private')->exists($path), 404);
        return response()->file(Storage::disk('private')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
