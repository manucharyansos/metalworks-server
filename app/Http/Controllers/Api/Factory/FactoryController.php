<?php

namespace App\Http\Controllers\Api\Factory;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Models\FactoryOrder;
use App\Models\FactoryOrderFile;
use App\Models\FactoryOrderStatus;
use App\Models\Order;
use App\Models\PmpFiles;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\OrderLog;
use App\Support\TaskAccess;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FactoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Factory::with('operators:users.id,users.name,users.factory_id');

        if ($user?->factory_id && $user->role?->name !== 'admin') {
            $query->whereKey($user->factory_id);
        }

        return response()->json($query->get());
    }

    public function create()
    {
        //
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('factories', 'name')],
        ]);

        $factory = Factory::create([
            'name' => $validated['name'],
        ]);

        return response()->json($factory, 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();

        if ($user?->factory_id && (int) $user->factory_id !== (int) $id && $user->role?->name !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $factory = Factory::with(['orders' => function ($query) use ($id, $user) {
            if ($user->role?->name === 'engineer') $query->where('creator_id', $user->id);
            $query->whereHas('factoryOrders', function ($q) use ($id, $user) {
                $q->where('factory_id', $id)
                    ->whereNull('completed_at');

                if ($user?->factory_id && $user->role?->name !== 'admin') {
                    $q->where(function ($sub) use ($user) {
                        $sub->whereNull('operator_id')
                            ->orWhere('operator_id', $user->id);
                    });
                }
            })
                ->with([
                    'factoryOrders' => function ($q) use ($id, $user) {
                        $q->where('factory_id', $id)
                            ->whereNull('completed_at');

                        if ($user?->factory_id && $user->role?->name !== 'admin') {
                            $q->where(function ($sub) use ($user) {
                                $sub->whereNull('operator_id')
                                    ->orWhere('operator_id', $user->id);
                            });
                        }

                        $q->with([
                            'files',
                            'operator:id,name',
                        ]);
                    },
                    'dates',
                    'creator',
                    'factoryOrders.operator:id,name',
                    'logs',
                ]);
        }])->find($id);

        if (!$factory) {
            return response()->json(['message' => 'Factory not found'], 404);
        }

        if ($factory->relationLoaded('orders')) $factory->setRelation('orders', $factory->orders->map(fn ($order) => TaskAccess::restrictOperatorRelations($order, $user)));
        return response()->json($factory);
    }

    public function edit(string $id)
    {
        //
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $factory = Factory::find($id);
        if (!$factory) {
            return response()->json(['message' => 'Factory not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('factories', 'name')->ignore($factory->id)],
        ]);

        $factory->update([
            'name' => $validated['name'],
        ]);

        return response()->json($factory, 200);
    }

    public function updateOrder(Request $request, $id): JsonResponse
    {
        $data = $request->validate([
            'factory_id' => 'required|integer|exists:factories,id',
            'factory_order.status' => 'present|nullable|string',
            'factory_order.canceling' => 'nullable|string|max:1000',
            'factory_order.cancel_date' => 'nullable|date',
            'factory_order.evidence_text' => 'nullable|string|max:10000',
            'evidence_photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
            'factory_order.admin_confirmation_date' => 'prohibited',
            'factory_order.engineer_confirmation_at' => 'prohibited',
            'factory_order.completed_at' => 'prohibited',
            'factory_order.confirmation_required' => 'prohibited',
            'factory_order.confirmation_method' => 'prohibited',
        ]);
        $user = $request->user();
        $factoryId = (int) $data['factory_id'];
        $management = in_array($user->role?->name, ['admin', 'manager'], true);
        abort_unless($management || ($user->factory_id && (int) $user->factory_id === $factoryId), 403);
        $status = $data['factory_order']['status'];
        $allowed = FactoryOrderStatus::whereNotNull('value')->pluck('value')->merge(['pending', 'waiting'])->all();
        if ($status !== null && !in_array($status, $allowed, true)) throw ValidationException::withMessages(['factory_order.status' => ['Արտադրամասի կարգավիճակը թույլատրելի չէ։']]);
        $storedPath = null;
        try {
            $order = DB::transaction(function () use ($request, $data, $id, $factoryId, $status, $user, $management, &$storedPath) {
                $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();
                $step = $order->factoryOrders()->where('factory_id', $factoryId)->lockForUpdate()->firstOrFail();
                abort_unless($management || !$step->operator_id || (int) $step->operator_id === (int) $user->id, 403);
                if ($step->is_blocked && in_array($status, ['confirmed', 'finished'], true)) {
                    throw ValidationException::withMessages(['factory_order.status' => ['Նախորդ արտադրամասի աշխատանքը դեռ չի ավարտվել։ Անհրաժեշտության դեպքում պետք է նաև ինժեների հաստատումը։']]);
                }
                if ($step->completed_at || in_array($step->status, ['finished', 'completed', 'done'], true)) {
                    throw ValidationException::withMessages(['factory_order.status' => ['Աշխատանքն արդեն ավարտված է կամ սպասում է ինժեների հաստատմանը։']]);
                }
                if ($status === 'finished') {
                    abort_unless((int) $user->factory_id === $factoryId && (!$step->operator_id || (int) $step->operator_id === (int) $user->id), 403, 'Ավարտը կարող է ուղարկել միայն այս արտադրամասի կատարողը։');
                    if ($step->confirmation_required) {
                        if ($step->confirmation_method === 'text') {
                            $text = trim((string) ($data['factory_order']['evidence_text'] ?? ''));
                            if ($text === '') throw ValidationException::withMessages(['factory_order.evidence_text' => ['Գրեք կատարված աշխատանքի հավաստումը։']]);
                            $step->evidence_text = $text;
                        } elseif ($step->confirmation_method === 'photo') {
                            if (!$request->hasFile('evidence_photo')) throw ValidationException::withMessages(['evidence_photo' => ['Ավելացրեք կատարված աշխատանքի նկարը։']]);
                            $storedPath = $request->file('evidence_photo')->store('companies/'.$order->company_id.'/task-evidence/'.$step->id, 'private');
                            $step->evidence_photo_path = $storedPath;
                        } else throw ValidationException::withMessages(['confirmation_method' => ['Հավաստման մեթոդը բացակայում է։']]);
                    }
                    $step->operator_finish_date = now();
                    $step->finish_date = now();
                    $step->completed_at = $step->confirmation_required ? null : now();
                }
                if ($status === 'canceled' && !trim((string) ($data['factory_order']['canceling'] ?? ''))) throw ValidationException::withMessages(['factory_order.canceling' => ['Ընտրեք մերժման պատճառը։']]);
                if ($status === 'date_changed' && empty($data['factory_order']['cancel_date'])) throw ValidationException::withMessages(['factory_order.cancel_date' => ['Ընտրեք նոր ժամկետը։']]);
                $previous = $step->status;
                $step->status = $status;
                $step->canceling = $status === 'canceled' ? $data['factory_order']['canceling'] : '';
                $step->cancel_date = $status === 'date_changed' ? $data['factory_order']['cancel_date'] : null;
                if (!$step->operator_id && $user->factory_id && $status && $status !== 'pending') $step->operator_id = $user->id;
                $step->save();
                OrderLog::create(['order_id' => $order->id, 'user_id' => $user->id, 'action' => 'factory_order.status_changed',
                    'message' => sprintf('Արտադրամաս «%s»․ %s → %s', $step->factory->name, $previous ?? 'Սպասում', $status ?? 'Սպասում'),
                    'meta' => ['factory_id' => $factoryId, 'factory_order_id' => $step->id, 'from_status' => $previous, 'to_status' => $status]]);
                $order->updateStatusIfAllFactoriesCompleted();
                return $order;
            });
        } catch (\Throwable $e) {
            if ($storedPath) Storage::disk('private')->delete($storedPath);
            throw $e;
        }
        $order->load('orderNumber', 'prefixCode', 'dates', 'factoryOrders.files', 'factoryOrders.factory', 'factoryOrders.operator:id,name', 'creator:id,name');
        return response()->json(TaskAccess::restrictOperatorRelations($order, $user));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $factory = Factory::find($id);
        if (!$factory) {
            return response()->json(['message' => 'Factory not found'], 404);
        }

        $factory->delete();

        return response()->json(null, 204);
    }

    public function getOrdersByFactories(Request $request): JsonResponse
    {
        $user = $request->user();
        if (
            !$user ||
            ($user->role?->name !== 'admin' && !$user->hasPermission('factory.view'))
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $factoryIds = $request->input('factory_ids');
        if (!$factoryIds) {
            return response()->json(['message' => 'Factory IDs are required'], 400);
        }

        $factoryIdsArray = array_values(array_filter(array_map('intval', explode(',', $factoryIds))));
        if (empty($factoryIdsArray)) {
            return response()->json(['message' => 'Invalid factory IDs'], 400);
        }

        if ($user->factory_id && $user->role?->name !== 'admin') {
            foreach ($factoryIdsArray as $factoryId) {
                if ((int) $user->factory_id !== $factoryId) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }
        }

        $orders = Order::whereHas('factoryOrders', function ($query) use ($factoryIdsArray, $user) {
            $query->whereIn('factory_id', $factoryIdsArray);
            if ($user->factory_id && !in_array($user->role?->name, ['admin', 'manager'], true)) TaskAccess::operatorSteps($query, $user);
        })->when($user->role?->name === 'engineer', fn ($q) => $q->where('creator_id', $user->id))->with('orderNumber', 'prefixCode', 'dates', 'factoryOrders.factory', 'factoryOrders.files', 'creator:id,name')->get()
            ->map(fn ($order) => TaskAccess::restrictOperatorRelations($order, $user));

        return response()->json($orders);
    }

    public function confirmOrderStatus(Request $request, $id): JsonResponse
    {
        return response()->json(['message' => 'Ավարտը հաստատում է առաջադրանքը ստեղծող ինժեները։'], 403);
    }

    public function getFile(Request $request, $filePath): JsonResponse
    {
        $decodedPath = $this->authorizeFilePath($request, $filePath);
        if (!$decodedPath) {
            return response()->json(['error' => 'File not found or access denied'], 404);
        }

        $fileContent = Storage::disk('private')->get($decodedPath);
        $originalName = basename($decodedPath);
        $fileSize = Storage::disk('private')->size($decodedPath);
        $mimeType = Storage::disk('private')->mimeType($decodedPath);

        return response()->json([
            'path' => $decodedPath,
            'original_name' => $originalName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'content' => base64_encode($fileContent),
        ], 200);
    }

    public function downloadFile(Request $request, $filePath): BinaryFileResponse|JsonResponse
    {
        $decodedPath = $this->authorizeFilePath($request, $filePath);
        if (!$decodedPath) {
            return response()->json(['error' => 'File not found or access denied'], 404);
        }

        $fullPath = Storage::disk('private')->path($decodedPath);

        return response()->download($fullPath, basename($decodedPath));
    }

    private function authorizeFilePath(Request $request, string $filePath): ?string
    {
        $decodedPath = ltrim(str_replace('\\', '/', urldecode($filePath)), '/');

        if ($decodedPath === '' || str_contains($decodedPath, '../') || $decodedPath === '..') {
            return null;
        }

        if (!Storage::disk('private')->exists($decodedPath)) {
            return null;
        }

        $user = $request->user();
        if (!$user) {
            return null;
        }

        $directFactoryFile = FactoryOrderFile::with('factoryOrder')
            ->where('path', $decodedPath)
            ->first();

        if ($directFactoryFile) {
            if (!$user->factory_id) {
                return $directFactoryFile->factoryOrder?->order && TaskAccess::canView($user, $directFactoryFile->factoryOrder->order) ? $decodedPath : null;
            }

            $factoryOrder = $directFactoryFile->factoryOrder;
            if (!$factoryOrder || (int) $user->factory_id !== (int) $factoryOrder->factory_id) {
                return null;
            }

            if ($factoryOrder->operator_id && (int) $factoryOrder->operator_id !== (int) $user->id) {
                return null;
            }

            return $decodedPath;
        }

        $pmpFile = PmpFiles::where('path', $decodedPath)->first();
        if ($pmpFile) return TaskAccess::canDownloadPmp($user, $pmpFile) ? $decodedPath : null;

        return $user->role?->name === 'admin' ? $decodedPath : null;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role?->name === 'admin', 403, 'Forbidden');
    }
}
