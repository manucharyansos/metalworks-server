<?php

namespace App\Http\Controllers\Api\Engineer;

use App\Http\Controllers\Controller;
use App\Mail\OrderCreated;
use App\Models\Factory;
use App\Models\FactoryOrder;
use App\Models\Order;
use App\Models\Pmp;
use App\Models\PrefixCode;
use App\Models\RemoteNumber;
use App\Models\SelectedFile;
use App\Models\User;
use App\Support\{ProductionRoute, TaskNotifier, TaskStages, TaskWorkflow};
use App\Models\TaskChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EngineerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['stage' => 'nullable|in:'.implode(',', TaskStages::STAGES),
            'factory_id' => 'nullable|integer|min:1', 'operator_id' => 'nullable|integer|min:1',
            'due_from' => 'nullable|date_format:Y-m-d', 'due_to' => 'nullable|date_format:Y-m-d',
            'filter_options' => 'sometimes|boolean', 'sort' => 'nullable|in:priority,newest']);
        try {
            $perPage = max(1, min((int) $request->query('per_page', 10), 100));
            $search = trim((string) $request->query('search', ''));
            $user = $request->user();

            $query = Order::with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory',
                'factoryOrders.files',
                'factoryOrders.operator:id,name',
                'factoryOrders.pendingRequest',
                'selectedFiles.pmpFile',
                'client.user',
                'creator:id,name',
                'logs.user:id,name',
            ])->where('creator_id', $user->id);

            $options = null;
            if ($request->boolean('filter_options')) {
                $steps = FactoryOrder::forWorkshops()->whereHas('order', fn ($q) => $q->where('creator_id', $user->id))
                    ->select('id', 'factory_id', 'operator_id')->with('factory:id,name', 'operator:id,name', 'helpers')->get();
                $pairs = fn ($relation) => $steps->pluck($relation)->filter()->unique('id')->sortBy('name')->values()
                    ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name]);
                $people = $steps->pluck('operator')->filter()->merge($steps->flatMap(fn ($s) => $s->helpers))->unique('id')->sortBy('name')->values()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);
                $options = ['factories' => $pairs('factory'), 'operators' => $people];
            }
            if ($request->filled('factory_id') || $request->filled('operator_id')) {
                $query->whereHas('factoryOrders', fn ($q) => $q->forWorkshops()
                    ->when($request->integer('factory_id'), fn ($q, $id) => $q->where('factory_id', $id))
                    ->when($request->integer('operator_id'), fn ($q, $id) => $q->where(fn ($people) => $people->where('operator_id', $id)->orWhereHas('helpers', fn ($h) => $h->where('users.id', $id)))));
            }
            if ($request->filled('due_from') || $request->filled('due_to')) {
                $query->whereHas('dates', fn ($q) => $q
                    ->when($request->query('due_from'), fn ($q, $day) => $q->whereDate('finish_date', '>=', $day))
                    ->when($request->query('due_to'), fn ($q, $day) => $q->whereDate('finish_date', '<=', $day)));
            }
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('orderNumber', fn ($w) => $w->where('number', 'like', "%{$search}%"))
                        ->orWhereHas('prefixCode', fn ($w) => $w->where('code', 'like', "%{$search}%"));
                });
            }

            // "Waiting" means waiting for this engineer: finished proof to confirm, or an operator's request to answer.
            $query->when($request->query('confirmation') === 'waiting', fn ($q) => $q->whereHas('factoryOrders', fn ($s) => $s->where(fn ($w) => $w->awaitingEngineer())
                ->orWhereHas('changeRequests', fn ($r) => $r->pending())));
            $stageSql = TaskStages::sql();
            $classified = (clone $query)->withoutEagerLoads()->select('orders.id')->selectRaw("$stageSql AS workflow_stage");
            $counts = DB::query()->fromSub($classified, 'task_stages')->select('workflow_stage')->selectRaw('COUNT(*) AS stage_count')
                ->groupBy('workflow_stage')->pluck('stage_count', 'workflow_stage')->map(fn ($count) => (int) $count);
            $priority = FactoryOrder::forWorkshops()->selectRaw("MAX(CASE priority WHEN 'urgent' THEN 2 WHEN 'high' THEN 1 ELSE 0 END)")
                ->whereColumn('factory_orders.order_id', 'orders.id')->whereNull('completed_at')
                ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['finished', 'completed', 'done', 'canceled', 'cancelled']));
            $deadline = \App\Models\Date::selectRaw('MIN(finish_date)')->whereColumn('dates.order_id', 'orders.id');
            $query->when($request->query('stage'), fn ($q, $stage) => $q->whereRaw("($stageSql) = ?", [$stage]))
                ->select('orders.*')->selectRaw("$stageSql AS workflow_stage")
                ->selectSub($priority, 'priority_rank')->selectSub($deadline, 'sort_deadline');
            if ($request->query('sort') === 'priority') {
                $query->orderByRaw("CASE WHEN ($stageSql) = 'completed' OR orders.status IN ('canceled', 'cancelled') THEN 1 ELSE 0 END")
                    ->orderByRaw('COALESCE(priority_rank, 0) DESC')->orderByRaw("COALESCE(sort_deadline, '9999-12-31') ASC");
            }
            $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

            return response()->json([
                'orders' => $page->items(),
                'filter_options' => $options,
                'stage_counts' => array_replace(array_fill_keys(TaskStages::STAGES, 0), $counts->all()),
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                    'last_page' => $page->lastPage(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                    'next_page_url' => $page->nextPageUrl(),
                    'prev_page_url' => $page->previousPageUrl(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Engineer order list failed', [
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Unable to load orders'], 500);
        }
    }

    public function create(): JsonResponse
    {
        try {
            $users = $this->customers();
            $pmps = Pmp::with(['remoteNumber', 'files.factory'])->get();
            $factories = Factory::activeWorkshops()->select('id', 'name', 'value')->get();

            return response()->json([
                'users' => $users,
                'pmps' => $pmps,
                'factories' => $factories,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Engineer create-order data load failed', ['exception' => $e]);

            return response()->json(['error' => 'Unable to load order form data'], 500);
        }
    }

    public function getFilesForFactoryAndOrder(Request $request, $factoryId, $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);
        $this->authorizeOwnedOrder($request, $order);

        $factoryOrder = FactoryOrder::with('files')
            ->where('order_id', $order->id)
            ->where('factory_id', $factoryId)
            ->first();

        return response()->json([
            'files' => $factoryOrder?->files ?? [],
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'user_id' => 'required|exists:users,id',
                'description' => 'sometimes|nullable|string',
                'name' => 'required|string|max:255',
                'status' => 'nullable|string',
                'finish_date' => 'required|date',
                'remote_number_id' => 'nullable|exists:remote_numbers,id',
                'pmp_id' => 'required|exists:pmps,id',
                'link_existing_files' => 'required|boolean',
                'selected_files' => 'sometimes|array',
                'selected_files.*.id' => 'required_with:selected_files|exists:pmp_files,id',
                'selected_files.*.quantity' => 'required_with:selected_files|integer|min:1',
                'stage_schedule' => 'sometimes|array|max:100',
                'stage_schedule.*.factory_id' => 'required|integer|distinct',
                'stage_schedule.*.operator_id' => 'required|integer',
                'stage_schedule.*.estimated_minutes' => 'required|integer|min:1|max:10080',
                'stage_schedule.*.starts_at' => 'nullable|date_format:Y-m-d H:i',
                'planned_starts' => 'sometimes|array|max:100',
                'planned_starts.*.factory_id' => 'required|integer|distinct',
                'planned_starts.*.starts_on' => 'required|date_format:Y-m-d',
                'factory_operators' => 'nullable|array',
                'factory_operators.*.factory_id' => 'required|exists:factories,id',
                'factory_operators.*.user_id' => 'required|exists:users,id',
            ] + TaskWorkflow::rules());

            $pmp = Pmp::with('files.factory')->findOrFail($validatedData['pmp_id']);
            $this->validateRemoteBelongsToPmp($validatedData['remote_number_id'] ?? null, $pmp->id);

            $selectedFiles = $this->resolveSelectedFiles($validatedData, $pmp);
            $this->validateSelectedFilesBelongToPmp($selectedFiles, $pmp, $validatedData['remote_number_id'] ?? null);
            $fileWorkshops = TaskWorkflow::workshopIds($pmp, $selectedFiles, $validatedData['file_routing'] ?? [], $validatedData['reference_file_visibility'] ?? []);
            $routeWorkshops = TaskWorkflow::routeOnlyWorkshopIds($validatedData['production_route'] ?? null, $fileWorkshops);
            TaskWorkflow::validateFiles($selectedFiles, $pmp, $validatedData['reference_file_visibility'] ?? [], $validatedData['workshop_settings'] ?? [], $validatedData['file_routing'] ?? [], $routeWorkshops);

            $factoryOperatorsInput = collect($validatedData['factory_operators'] ?? []);
            $this->validateFactoryOperators($factoryOperatorsInput, array_merge($fileWorkshops, $routeWorkshops));
            $factoryOperators = $factoryOperatorsInput->keyBy('factory_id');

            $order = DB::transaction(function () use (
                $request,
                $validatedData,
                $pmp,
                $selectedFiles,
                $factoryOperators,
                $routeWorkshops
            ) {
                $order = Order::create([
                    'user_id' => $validatedData['user_id'],
                    'creator_id' => $request->user()->id,
                    'name' => $validatedData['name'],
                    'description' => $validatedData['description'] ?? '',
                    'status' => 'pending',
                    'remote_number_id' => $validatedData['remote_number_id'] ?? null,
                    'link_existing_files' => $validatedData['link_existing_files'],
                    'reference_file_visibility' => $validatedData['reference_file_visibility'] ?? [],
                ] + TaskWorkflow::settings($validatedData));

                $order->orderNumber()->create(['number' => $this->generateOrderNumber()]);
                $order->prefixCode()->create(['code' => $this->generateUniquePrefixCode()]);
                $order->dates()->create(['finish_date' => $validatedData['finish_date']]);

                TaskWorkflow::attachFiles($order, $pmp, $selectedFiles, $validatedData['reference_file_visibility'] ?? [], $factoryOperators, $validatedData['workshop_settings'] ?? [], $validatedData['file_routing'] ?? [], $routeWorkshops);
                $plan = $validatedData['production_route'] ?? \App\Support\ProjectFilePlan::taskPlan($pmp, $selectedFiles, $validatedData['file_routing'] ?? [], $validatedData['reference_file_visibility'] ?? []);
                if ($plan) ProductionRoute::apply($order, $plan);
                else ProductionRoute::refreshReadiness($order, null);

                // Days chosen from an operator's free time on the workload calendar.
                $today = \Carbon\CarbonImmutable::now('Asia/Yerevan')->toDateString();
                $finish = substr((string) $validatedData['finish_date'], 0, 10);
                foreach ($validatedData['planned_starts'] ?? [] as $entry) {
                    $step = $order->factoryOrders()->where('factory_id', $entry['factory_id'])->first();
                    abort_unless($step && $step->factory?->is_workshop, 422, 'Պլանավորված սկիզբը նշված է արտադրամասի համար, որի աշխատանք այս առաջադրանքում չկա։');
                    abort_if($entry['starts_on'] < $today || $entry['starts_on'] > $finish, 422, 'Պլանավորված սկիզբը պետք է լինի այսօրվանից մինչև ավարտի ժամկետը։');
                    $step->update(['planned_start' => $entry['starts_on']]);
                }

                // Earlier confirmed reservations must exist before dependent stages are checked.
                $schedule = collect($validatedData['stage_schedule'] ?? [])->sortBy('starts_at');
                foreach ($schedule as $entry) {
                    $step = $order->factoryOrders()->where('factory_id', $entry['factory_id'])->first();
                    abort_unless($step && (int) $step->operator_id === (int) $entry['operator_id'], 422, 'Ստուգեք քայլի արտադրամասն ու կատարողին։');
                    $step->estimated_minutes = $entry['estimated_minutes'];
                    $step->save();
                    if (!empty($entry['starts_at'])) {
                        $start = \Carbon\CarbonImmutable::createFromFormat('Y-m-d H:i', $entry['starts_at'], 'Asia/Yerevan')->second(0);
                        \App\Support\StageReservation::reserve($request->user(), $step, $start->format('Y-m-d H:i:s'),
                            $start->addMinutes($entry['estimated_minutes'])->format('Y-m-d H:i:s'));
                    }
                }
                return $order;
            });

            TaskNotifier::stepsAssigned($request->user(), $order);

            try {
                $userEmail = User::find($validatedData['user_id'])?->email;
                if ($userEmail) {
                    $orderUrl = route('orders.show', ['id' => $order->id]);
                    Mail::to($userEmail)->send(new OrderCreated($order, $orderUrl));
                }
            } catch (\Throwable $mailError) {
                Log::warning('Engineer order created but notification email failed', [
                    'order_id' => $order->id,
                    'exception' => $mailError,
                ]);
            }

            return response()->json([
                'message' => 'Order created successfully',
                'order' => $order->load([
                    'orderNumber',
                    'prefixCode',
                    'dates',
                    'factoryOrders.files',
                    'factoryOrders.operator',
                    'selectedFiles.pmpFile',
                    'client.user',
                    'creator:id,name',
                'logs.user:id,name',
                ]),
            ], 201);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Engineer order creation failed', [
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Unable to create order'], 500);
        }
    }

    public function show(Request $request, string $engineer): JsonResponse
    {
        $order = Order::findOrFail($engineer);
        $this->authorizeOwnedOrder($request, $order);

        $order->load([
            'orderNumber',
            'prefixCode',
            'dates',
            'factoryOrders.factory',
            'factoryOrders.files',
            'factoryOrders.operator:id,name',
            'factoryOrders.pendingRequest',
            'selectedFiles.pmpFile',
            'user',
            'client.user',
            'creator:id,name',
            'logs.user:id,name',
        ]);

        return response()->json(['order' => $order], 200);
    }

    public function edit(Request $request, string $id): JsonResponse
    {
        try {
            $order = Order::with([
                'orderNumber',
                'prefixCode',
                'dates',
                'factoryOrders.factory',
                'factoryOrders.files',
                'selectedFiles.pmpFile',
                'client.user',
            ])->findOrFail($id);

            $this->authorizeOwnedOrder($request, $order);

            $users = $this->customers();
            $pmps = Pmp::with(['remoteNumber', 'files.factory'])->get();
            $factories = Factory::activeWorkshops()->select('id', 'name', 'value')->get();

            return response()->json([
                'order' => $order,
                'users' => $users,
                'pmps' => $pmps,
                'factories' => $factories,
            ], 200);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Order not found'], 404);
        } catch (\Throwable $e) {
            Log::error('Engineer order edit data load failed', [
                'order_id' => $id,
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Unable to load order'], 500);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'user_id' => 'required|exists:users,id',
                'description' => 'sometimes|nullable|string',
                'name' => 'required|string|max:255',
                'status' => 'nullable|string',
                'finish_date' => 'required|date',
                'remote_number_id' => 'nullable|exists:remote_numbers,id',
                'pmp_id' => 'required|exists:pmps,id',
                'link_existing_files' => 'sometimes|boolean',
                'selected_files' => 'sometimes|array',
                'selected_files.*.id' => 'required|exists:pmp_files,id',
                'selected_files.*.quantity' => 'required|integer|min:1',
            ] + TaskWorkflow::rules());

            $order = Order::findOrFail($id);
            $this->authorizeOwnedOrder($request, $order);

            if ($order->logs()->whereIn('action', ['factory_order.work_added', 'factory_order.operator_changed', 'factory_order.evidence_requirements_changed', 'order.file_version_changed'])->exists()) {
                throw ValidationException::withMessages(['order' => ['Այս առաջադրանքի աշխատանքները կառավարեք արտադրամասային աշխատանքների բաժնից՝ պահպանելով հերթականությունն ու կատարողներին։']]);
            }
            if ($order->factoryOrders()->whereNotIn('status', ['pending', 'waiting'])->exists()) {
                throw ValidationException::withMessages(['order' => ['Առաջադրանքն արդեն ընդունված է։ Ֆայլերն ու հաստատման պայմանները փոփոխել հնարավոր չէ։']]);
            }
            if ($order->factoryOrders()->whereHas('changeRequests', fn ($r) => $r->pending())->exists()) {
                throw ValidationException::withMessages(['order' => ['Կատարողի հարցումը սպասում է ձեր պատասխանին։ Նախ պատասխանեք դրան։']]);
            }
            $pmp = Pmp::with('files.factory')->findOrFail($validatedData['pmp_id']);
            $this->validateRemoteBelongsToPmp($validatedData['remote_number_id'] ?? null, $pmp->id);

            $selectedFiles = $validatedData['selected_files'] ?? [];
            if (!empty($selectedFiles)) {
                $this->validateSelectedFilesBelongToPmp($selectedFiles, $pmp, $validatedData['remote_number_id'] ?? null);
            }
            $routing = $validatedData['file_routing'] ?? $order->factoryOrders()->with('files')->get()->flatMap(fn ($step) => $step->files->reject(fn ($file) => $file->is_reference_file)->map(fn ($file) => ['file_id' => $file->id, 'factory_id' => $step->factory_id]))->groupBy('file_id')->map(fn ($rows, $id) => ['file_id' => (int) $id, 'factory_ids' => $rows->pluck('factory_id')->unique()->values()->all()])->values()->all();
            $routing = array_values(array_filter($routing, fn ($row) => in_array($row['file_id'], array_column($selectedFiles, 'id'))));
            if (!empty($selectedFiles)) TaskWorkflow::validateFiles($selectedFiles, $pmp, $validatedData['reference_file_visibility'] ?? $order->reference_file_visibility ?? [], [], $routing);

            DB::transaction(function () use ($request, $validatedData, $order, $pmp, $selectedFiles, $routing) {
                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($order->logs()->whereIn('action', ['factory_order.work_added', 'factory_order.operator_changed', 'factory_order.evidence_requirements_changed', 'order.file_version_changed'])->exists()
                    || $order->factoryOrders()->whereNotIn('status', ['pending', 'waiting'])->exists()
                    || $order->factoryOrders()->whereHas('changeRequests', fn ($r) => $r->pending())->exists()) {
                    throw ValidationException::withMessages(['order' => ['Արտադրամասային աշխատանքներն արդեն փոխվել են։ Թարմացրեք առաջադրանքը։']]);
                }
                $order->update([
                    'user_id' => $validatedData['user_id'],
                    'name' => $validatedData['name'],
                    'description' => array_key_exists('description', $validatedData) ? ($validatedData['description'] ?? '') : $order->description,
                    'status' => $order->status,
                    'remote_number_id' => $validatedData['remote_number_id'] ?? null,
                    'link_existing_files' => $validatedData['link_existing_files'] ?? $order->link_existing_files,
                    'reference_file_visibility' => $validatedData['reference_file_visibility'] ?? $order->reference_file_visibility,
                ] + TaskWorkflow::settings(array_merge($order->toArray(), $validatedData)));
                if ($order->wasChanged(['confirmation_required', 'confirmation_method'])) $order->factoryOrders()->update(TaskWorkflow::settings($order->toArray()));

                $order->dates()->updateOrCreate(
                    ['order_id' => $order->id],
                    ['finish_date' => $validatedData['finish_date']]
                );

                if (($validatedData['link_existing_files'] ?? false) && !empty($selectedFiles)) {
                    // The jobs are rebuilt from the new files, but the people and the
                    // per-workshop deadline, priority and proof chosen at creation stay.
                    $kept = $order->factoryOrders()->withCount('files')->get()->keyBy('factory_id');
                    $keptRoute = ProductionRoute::snapshot($kept);
                    // Workshops that were only route stages (no files) keep their job.
                    $activeWorkshops = Factory::activeWorkshops()->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $routeOnly = array_values(array_diff(
                        array_intersect($kept->filter(fn ($step) => (int) $step->files_count === 0)->keys()->map(fn ($id) => (int) $id)->all(), $activeWorkshops),
                        TaskWorkflow::workshopIds($pmp, $selectedFiles, $routing, $order->reference_file_visibility ?? [])
                    ));
                    $operators = $kept->filter(fn ($step) => $step->operator_id)
                        ->map(fn ($step) => ['factory_id' => $step->factory_id, 'user_id' => $step->operator_id]);
                    $workshopSettings = $kept->map(fn ($step) => [
                        'factory_id' => $step->factory_id, 'confirmation_required' => (bool) $step->confirmation_required,
                        'confirmation_method' => $step->confirmation_method, 'priority' => $step->priority ?: 'normal',
                        'deadline' => $step->deadline ? substr((string) $step->getRawOriginal('deadline'), 0, 10) : null,
                    ])->values()->all();
                    $order->selectedFiles()->delete();
                    $order->factoryOrders()->each(function ($step) { $step->files()->detach(); $step->delete(); });
                    TaskWorkflow::attachFiles($order, $pmp, $selectedFiles, $order->reference_file_visibility ?? [], $operators, $workshopSettings, $routing, $routeOnly);
                    $currentFactories = $order->factoryOrders()->pluck('factory_id')->all();
                    $preserved = array_values(array_filter($keptRoute, fn ($stage) => in_array($stage['factory_id'], $currentFactories)));
                    foreach (array_diff($currentFactories, array_column($preserved, 'factory_id')) as $factoryId) {
                        $preserved[] = ['factory_id' => (int) $factoryId, 'predecessor_factory_ids' => [], 'deadline' => null];
                    }
                    ProductionRoute::apply($order, $validatedData['production_route'] ?? ['mode' => 'custom', 'stages' => $preserved]);
                } elseif (isset($validatedData['production_route'])) {
                    ProductionRoute::apply($order, $validatedData['production_route'], $request->user());
                }
            });

            return response()->json([
                'message' => 'Order updated successfully',
                'order' => $order->fresh()->load([
                    'orderNumber',
                    'prefixCode',
                    'dates',
                    'factoryOrders.factory',
                    'factoryOrders.files',
                    'selectedFiles.pmpFile',
                    'creator:id,name',
                'logs.user:id,name',
                ]),
            ], 200);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Order not found'], 404);
        } catch (\Throwable $e) {
            Log::error('Engineer order update failed', [
                'order_id' => $id,
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Unable to update order'], 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $order = Order::findOrFail($id);
            $this->authorizeOwnedOrder($request, $order);

            DB::transaction(function () use ($order) {
                $order->selectedFiles()->delete();
                $order->factoryOrders()->each(function ($factoryOrder) {
                    $factoryOrder->files()->detach();
                    $factoryOrder->delete();
                });
                $order->orderNumber()->delete();
                $order->prefixCode()->delete();
                $order->dates()->delete();
                $order->delete();
            });

            return response()->json(['message' => 'Order deleted successfully'], 200);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Order not found'], 404);
        } catch (\Throwable $e) {
            Log::error('Engineer order deletion failed', [
                'order_id' => $id,
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Unable to delete order'], 500);
        }
    }

    private function authorizeOwnedOrder(Request $request, Order $order): void
    {
        abort_unless(
            (int) $order->creator_id === (int) $request->user()->id,
            403,
            'Forbidden'
        );
    }

    private function validateRemoteBelongsToPmp(?int $remoteNumberId, int $pmpId): void
    {
        if (!$remoteNumberId) {
            return;
        }

        $belongs = RemoteNumber::whereKey($remoteNumberId)
            ->where('pmp_id', $pmpId)
            ->exists();

        if (!$belongs) {
            throw ValidationException::withMessages([
                'remote_number_id' => ['Ընտրված հեռակա համարը չի պատկանում այս նախագծին։'],
            ]);
        }
    }

    /**
     * A chosen operator must work in that workshop, and the workshop must really
     * get a job in this task. Silently dropping a choice would leave the job
     * unassigned while the engineer believes somebody is on it.
     */
    private function validateFactoryOperators($factoryOperators, array $workshopIds): void
    {
        $seen = [];
        $factoryOperators->each(function ($entry) use (&$seen, $workshopIds) {
            $factoryId = (int) $entry['factory_id'];
            if (isset($seen[$factoryId])) {
                throw ValidationException::withMessages([
                    'factory_operators' => ['Յուրաքանչյուր արտադրամասի համար կարելի է ընտրել միայն մեկ կատարող։'],
                ]);
            }
            $seen[$factoryId] = true;
            if (!in_array($factoryId, $workshopIds, true)) {
                throw ValidationException::withMessages([
                    'factory_operators' => ['Կատարողն ընտրված է արտադրամասի համար, որի աշխատանք այս առաջադրանքում չկա։'],
                ]);
            }
            $operator = User::find($entry['user_id']);
            if (!$operator || !User::whereKey($operator->id)->assignedToFactory($factoryId)->exists()) {
                throw ValidationException::withMessages([
                    'factory_operators' => ['Ընտրված աշխատակիցը չի պատկանում ընտրված արտադրամասին։'],
                ]);
            }
        });
    }

    /** Only registered customer accounts can be a task's client, so only they are offered. */
    private function customers()
    {
        return User::select('id', 'name', 'email')->forRoles(['authenticatedUser'])->orderBy('name')->get();
    }

    private function resolveSelectedFiles(array $validatedData, Pmp $pmp): array
    {
        if ($validatedData['link_existing_files']) {
            $selectedFiles = $validatedData['selected_files'] ?? [];
        } else {
            $files = $pmp->files;
            if (!empty($validatedData['remote_number_id'])) {
                $files = $files->where('remote_number_id', (int) $validatedData['remote_number_id']);
            }

            $selectedFiles = $files->map(fn ($file) => [
                'id' => $file->id,
                'quantity' => (int) $file->quantity > 0 ? (int) $file->quantity : 1,
            ])->values()->toArray();
        }

        if (empty($selectedFiles)) {
            throw ValidationException::withMessages([
                'selected_files' => [$validatedData['link_existing_files']
                    ? 'Առաջադրանք ստեղծելու համար ընտրեք առնվազն մեկ ֆայլ։'
                    : 'Ընտրված նախագծում կամ ենթախմբում ֆայլեր չկան։ Նախ ավելացրեք ֆայլեր։'],
            ]);
        }

        return $selectedFiles;
    }

    private function validateSelectedFilesBelongToPmp(array $selectedFiles, Pmp $pmp, ?int $remoteNumberId = null): void
    {
        foreach ($selectedFiles as $selectedFile) {
            $file = $pmp->files->firstWhere('id', (int) $selectedFile['id']);
            if (!$file) {
                throw ValidationException::withMessages([
                    'selected_files' => ['Ընտրված ֆայլերից մեկը չի պատկանում այս նախագծին։'],
                ]);
            }
            if ($remoteNumberId && (int) $file->remote_number_id !== $remoteNumberId) {
                throw ValidationException::withMessages([
                    'selected_files' => ['Ընտրված ֆայլերից մեկը չի պատկանում ընտրված ենթախմբին։'],
                ]);
            }
        }
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
