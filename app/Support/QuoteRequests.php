<?php

namespace App\Support;

use App\Models\{CompanyMembership, Factory, Material, MaterialCategory, MaterialGroup, PricingMaterial, PricingSetting, PricingThickness, QuoteRequest, QuoteRequestItem, TaskNotification, User};
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Log, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer order requests. A customer fills one draft with uploaded parts,
 * sends it, and the company (managers and admins) answers with a price and a
 * ready date or turns it down. The customer then accepts or declines the offer.
 *
 * draft → submitted → quoted → accepted | declined
 *            ↓  ↘ rejected (by the company, also from quoted)
 *        cancelled (by the customer, before an answer)
 */
final class QuoteRequests
{
    public const EXTENSIONS = ['dxf', 'dwg', 'step', 'stp', 'igs', 'iges', 'sldprt', 'sldasm', 'slddrw', 'pdf', 'png', 'jpg', 'jpeg'];
    public const MAX_ITEMS = 20;
    public const MAX_FILE_KB = 51200;
    private const STAFF_ROLES = ['admin', 'manager'];
    // The company may answer again until the customer decides.
    private const ANSWERABLE = ['submitted', 'quoted'];

    /** The signed-in account when it is a customer with open access in this company. */
    public static function customer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->role?->name === 'authenticatedUser', 403, 'Հայտ կարող է ուղարկել միայն պատվիրատուն։');
        abort_unless(User::query()->forRoles(['authenticatedUser'])->whereKey($user->id)->exists(), 403, 'Ձեր պատվիրատուի հասանելիությունը փակված է։ Դիմեք ընկերությանը։');
        return $user;
    }

    public static function options(): array
    {
        $settings = PricingSetting::current();
        return [
            'workshops' => self::workshops()->map(fn (Factory $factory) => [
                'id' => $factory->id, 'name' => $factory->name, 'value' => $factory->value, 'powder' => TaskPaint::isPowder($factory),
            ])->values(),
            'extensions' => self::EXTENSIONS,
            'max_items' => self::MAX_ITEMS,
            'max_file_mb' => (int) (self::MAX_FILE_KB / 1024),
            // Only the names and thicknesses; the prices stay with the company.
            'pricing' => [
                'enabled' => (bool) $settings->enabled, 'vat_included' => (bool) $settings->vat_included,
                'customer_note' => $settings->customer_note, 'discounts' => array_values($settings->discounts ?? []),
                'materials' => PricingMaterial::where('is_active', true)->with(['thicknesses' => fn ($q) => $q->where('is_active', true)])
                    ->orderBy('sort')->orderBy('name')->get()
                    ->filter(fn (PricingMaterial $material) => $material->thicknesses->isNotEmpty())
                    ->map(fn (PricingMaterial $material) => ['id' => $material->id, 'name' => $material->name,
                        'thicknesses' => $material->thicknesses->map(fn (PricingThickness $t) => ['id' => $t->id, 'thickness' => $t->thickness])->values()])
                    ->values(),
            ],
            'catalog' => self::catalog(),
        ];
    }

    /**
     * The company's Materials section, for the template builder: each material
     * with the thicknesses and sheet sizes it is stocked in. Names and sizes
     * only: customers see this, so internal notes on a sheet stay out.
     */
    private static function catalog(): array
    {
        $groups = MaterialGroup::pluck('name', 'id');
        return MaterialCategory::with(['materials' => fn ($q) => $q->where('thickness', '>', 0)->orderBy('thickness')->orderBy('id')])
            ->orderBy('name')->get()
            ->filter(fn (MaterialCategory $category) => $category->materials->isNotEmpty())
            ->map(fn (MaterialCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'group' => $groups[$category->material_group_id] ?? null,
                'sheets' => $category->materials->map(fn (Material $material) => [
                    'id' => $material->id,
                    'thickness' => (float) $material->thickness,
                    'width' => $material->width ? (float) $material->width : null,
                    'length' => $material->length ? (float) $material->length : null,
                ])->values()->all(),
            ])->values()->all();
    }

    public static function itemRules(): array
    {
        return [
            'workshop_ids' => 'sometimes|array|max:20', 'workshop_ids.*' => 'integer|distinct',
            'pricing_thickness_id' => 'nullable|integer',
            'material' => 'nullable|string|max:255', 'thickness' => 'nullable|numeric|min:0.1|max:300',
            'quantity' => 'sometimes|integer|min:1|max:1000000', 'bends' => 'nullable|integer|min:0|max:500',
            'paint_ral' => 'nullable|string|max:40', 'primer_required' => 'sometimes|boolean',
            'note' => 'nullable|string|max:2000',
        ];
    }

    public static function submitRules(): array
    {
        return [
            'title' => 'nullable|string|max:255', 'note' => 'nullable|string|max:5000',
            'desired_date' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{5,40}$/'],
        ];
    }

    public static function answerRules(): array
    {
        return [
            'price' => 'required|numeric|min:0|max:999999999999',
            'ready_date' => 'nullable|date_format:Y-m-d',
            'response_note' => 'nullable|string|max:5000',
        ];
    }

    public static function draft(User $customer): ?QuoteRequest
    {
        return QuoteRequest::where('customer_id', $customer->id)->where('status', 'draft')->orderBy('id')->first();
    }

    public static function addItem(User $customer, UploadedFile $file, array $data): QuoteRequestItem
    {
        $name = Str::limit(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 250, '');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => ['Այս ձևաչափի ֆայլ չի ընդունվում։ Թույլատրված են՝ '.implode(', ', self::EXTENSIONS).'։']]);
        }
        // A flat drawing is cut on the sheet laser unless the customer says otherwise.
        if (!array_key_exists('workshop_ids', $data) && $extension === 'dxf') {
            $laser = self::workshops()->first(fn (Factory $factory) => strtoupper(trim((string) $factory->value)) === 'DXF');
            if ($laser) $data['workshop_ids'] = [$laser->id];
        }
        $fields = self::itemFields($data);
        // Stored first, so a retried transaction never writes the file twice.
        $stored = $file->storeAs(self::directory($customer), Str::uuid().'.'.$extension, 'private');
        if (!$stored) throw new \RuntimeException('Quote file storage failed');
        $fields['metrics'] = self::measure($stored, $extension);
        try {
            return DB::transaction(function () use ($customer, $fields, $stored, $name, $file) {
                self::lockCustomer($customer);
                $draft = self::draft($customer) ?? QuoteRequest::create(['customer_id' => $customer->id, 'status' => 'draft']);
                if ($draft->items()->count() >= self::MAX_ITEMS) {
                    throw ValidationException::withMessages(['file' => [sprintf('Մեկ հայտում կարելի է ավելացնել առավելագույնը %d ֆայլ։', self::MAX_ITEMS)]]);
                }
                return QuoteRequestItem::create(array_merge(['quantity' => 1], $fields, [
                    'quote_request_id' => $draft->id, 'path' => $stored, 'original_name' => $name, 'size' => (int) $file->getSize(),
                ]));
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($stored);
            throw $e;
        }
    }

    public static function addAssembly(User $customer, string $key, array $parts, array $files): QuoteRequest
    {
        $written = [];
        $disk = Storage::disk('private');
        try {
            return DB::transaction(function () use ($customer, $key, $parts, $files, &$written, $disk) {
                self::lockCustomer($customer);
                $previous = QuoteRequestItem::where('assembly_key', $key)
                    ->whereHas('quote', fn ($q) => $q->where('customer_id', $customer->id))->first();
                if ($previous) {
                    abort_unless($previous->quote->status === 'draft', 409);
                    return $previous->quote;
                }
                $draft = self::draft($customer);
                if (($draft?->items()->count() ?? 0) + count($parts) > self::MAX_ITEMS)
                    throw ValidationException::withMessages(['parts' => ['Հավաքը գերազանցում է հայտի դետալների սահմանաչափը։']]);

                foreach ($parts as $i => $part) {
                    $item = self::addItem($customer, $files[$i], $part['fields']);
                    $written[] = $item->path;
                    $geometry = $item->path.'.json';
                    $written[] = $geometry;
                    if (!$disk->put($geometry, json_encode($part['geometry'], JSON_THROW_ON_ERROR)))
                        throw new \RuntimeException('Assembly geometry storage failed');
                    $item->update(['assembly_key' => $key, 'assembly_position' => $i]);
                }
                return $item->quote()->firstOrFail();
            });
        } catch (\Throwable $error) {
            if ($written) $disk->delete($written);
            throw $error;
        }
    }

    public static function updateItem(User $customer, QuoteRequestItem $item, array $data): QuoteRequestItem
    {
        return DB::transaction(function () use ($customer, $item, $data) {
            $locked = self::draftItem($customer, $item->id);
            $locked->update(self::itemFields($data, $locked));
            return $locked->fresh();
        }, 3);
    }

    public static function removeItem(User $customer, QuoteRequestItem $item): void
    {
        $path = DB::transaction(function () use ($customer, $item) {
            $locked = self::draftItem($customer, $item->id);
            $path = $locked->path;
            $locked->delete();
            return $path;
        }, 3);
        Storage::disk('private')->delete([$path, $path.'.json']);
    }

    public static function submit(User $customer, array $data): QuoteRequest
    {
        $quote = DB::transaction(function () use ($customer, $data) {
            self::lockCustomer($customer);
            $draft = self::draft($customer);
            if (!$draft || !$draft->items()->exists()) {
                throw ValidationException::withMessages(['items' => ['Ավելացրեք առնվազն մեկ ֆայլ։']]);
            }
            $draft = QuoteRequest::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            // The estimate is kept as the customer saw it; later price changes do not touch it.
            $estimate = QuotePricing::quote($draft->load('items'));
            foreach ($draft->items as $item) $item->update(['pricing' => $estimate['items'][$item->id] ?? null]);
            unset($estimate['items']);
            $draft->update([
                'estimate' => $estimate,
                'number' => self::nextNumber(), 'status' => 'submitted', 'submitted_at' => now(),
                'title' => self::text($data['title'] ?? null), 'note' => self::text($data['note'] ?? null),
                'desired_date' => $data['desired_date'] ?? null, 'phone' => self::text($data['phone'] ?? null),
            ]);
            return $draft;
        }, 3);
        self::notifyStaff($customer, $quote, 'quote.submitted', sprintf('Նոր պատվերի հայտ %s, %s', $quote->number, self::person($customer)));
        return $quote;
    }

    public static function cancel(User $customer, QuoteRequest $quote): QuoteRequest
    {
        $quote = self::transition($quote, ['submitted'], 'cancelled', fn () => ['decided_at' => now()],
            'Հայտն արդեն պատասխանված է։ Եթե առաջարկը չի համապատասխանում, մերժեք այն։', $customer);
        self::notifyStaff($customer, $quote, 'quote.cancelled', sprintf('%s հայտը չեղարկվել է պատվիրատուի կողմից', $quote->number));
        return $quote;
    }

    public static function decide(User $customer, QuoteRequest $quote, bool $accept): QuoteRequest
    {
        $quote = self::transition($quote, ['quoted'], $accept ? 'accepted' : 'declined', fn () => ['decided_at' => now()],
            'Այս հայտի համար ընթացիկ առաջարկ չկա։', $customer);
        self::notifyStaff($customer, $quote, $accept ? 'quote.accepted' : 'quote.declined', sprintf(
            $accept ? '%s հայտի առաջարկը ընդունվել է' : '%s հայտի առաջարկը մերժվել է պատվիրատուի կողմից', $quote->number));
        return $quote;
    }

    public static function answer(User $staff, QuoteRequest $quote, array $data): QuoteRequest
    {
        $quote = self::transition($quote, self::ANSWERABLE, 'quoted', fn () => [
            'price' => round((float) $data['price'], 2), 'ready_date' => $data['ready_date'] ?? null,
            'response_note' => self::text($data['response_note'] ?? null), 'responded_by' => $staff->id, 'responded_at' => now(),
        ], 'Այս հայտին այլևս պատասխանել հնարավոր չէ։');
        self::notify((int) $quote->customer_id, $staff, $quote, 'quote.quoted', sprintf('%s հայտին պատասխանել են. արժեքը՝ %s ֏', $quote->number, number_format((float) $quote->price, 0, '.', ' ')));
        return $quote;
    }

    public static function reject(User $staff, QuoteRequest $quote, string $note): QuoteRequest
    {
        $quote = self::transition($quote, self::ANSWERABLE, 'rejected', fn () => [
            'response_note' => self::text($note), 'responded_by' => $staff->id, 'responded_at' => now(),
        ], 'Այս հայտին այլևս պատասխանել հնարավոր չէ։');
        self::notify((int) $quote->customer_id, $staff, $quote, 'quote.rejected', sprintf('%s հայտը մերժվել է', $quote->number));
        return $quote;
    }

    /** A request as its customer (or, with $staff, the company) sees it. */
    public static function present(QuoteRequest $quote, bool $staff = false): array
    {
        $quote->loadMissing('items');
        $workshops = self::workshopNames($quote->items->pluck('workshop_ids')->flatten()->filter()->unique()->all());
        // A draft is priced from today's list; a sent request keeps the estimate it was sent with.
        $live = $quote->status === 'draft' ? QuotePricing::quote($quote) : null;
        $pricing = function (QuoteRequestItem $item) use ($live, $staff) {
            $price = $live ? ($live['items'][$item->id] ?? null) : $item->pricing;
            // How the price is built is the company's own business.
            if ($price && !$staff) unset($price['breakdown']);
            return $price;
        };
        $data = [
            'id' => $quote->id, 'number' => $quote->number, 'status' => $quote->status, 'title' => $quote->title,
            'note' => $quote->note, 'desired_date' => $quote->desired_date?->format('Y-m-d'), 'phone' => $quote->phone,
            'submitted_at' => $quote->submitted_at?->toIso8601String(), 'price' => $quote->price, 'currency' => $quote->currency,
            'ready_date' => $quote->ready_date?->format('Y-m-d'), 'response_note' => $quote->response_note,
            'responded_at' => $quote->responded_at?->toIso8601String(), 'decided_at' => $quote->decided_at?->toIso8601String(),
            'estimate' => $live ? array_diff_key($live, ['items' => true]) : $quote->estimate,
            'items' => $quote->items->map(fn (QuoteRequestItem $item) => [
                'id' => $item->id, 'assembly_key' => $item->assembly_key, 'assembly_position' => $item->assembly_position, 'original_name' => $item->original_name, 'size' => $item->size,
                'workshop_ids' => array_values(array_map('intval', $item->workshop_ids ?? [])),
                'workshops' => array_values(array_filter(array_map(fn ($id) => $workshops[(int) $id] ?? null, $item->workshop_ids ?? []))),
                'pricing_thickness_id' => $item->pricing_thickness_id,
                'material' => $item->material, 'thickness' => $item->thickness, 'quantity' => $item->quantity, 'bends' => $item->bends,
                'paint_ral' => $item->paint_ral, 'primer_required' => $item->primer_required, 'note' => $item->note,
                'metrics' => $item->metrics, 'pricing' => $pricing($item),
            ])->values(),
        ];
        if ($staff) {
            $customer = User::withoutGlobalScopes()->with(['client' => fn ($q) => $q->where('company_id', $quote->company_id)])->find($quote->customer_id);
            $data['customer'] = $customer ? [
                'id' => $customer->id, 'name' => self::person($customer), 'email' => $customer->email,
                'phone' => $quote->phone ?: ($customer->client?->phone ?: $customer->phone),
            ] : null;
            $data['responder'] = $quote->responded_by ? User::withoutGlobalScopes()->whereKey($quote->responded_by)->value('name') : null;
        }
        return $data;
    }

    public static function path(QuoteRequestItem $item): string
    {
        return $item->path;
    }

    private static function workshops()
    {
        return Factory::activeWorkshops()->orderBy('factories.id')->get();
    }

    private static function workshopNames(array $ids): array
    {
        if (!$ids) return [];
        return Factory::workshops()->whereIn('factories.id', array_map('intval', $ids))->get()
            ->mapWithKeys(fn (Factory $factory) => [(int) $factory->id => ['id' => $factory->id, 'name' => $factory->name, 'value' => $factory->value]])->all();
    }

    private static function itemFields(array $data, ?QuoteRequestItem $item = null): array
    {
        $fields = [];
        if (array_key_exists('workshop_ids', $data)) {
            $ids = array_values(array_unique(array_map('intval', $data['workshop_ids'] ?? [])));
            $active = self::workshops()->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (array_diff($ids, $active)) {
                throw ValidationException::withMessages(['workshop_ids' => ['Ընտրեք ընկերության գործող ծառայությունները։']]);
            }
            // Kept in the company's own order of services.
            $fields['workshop_ids'] = array_values(array_intersect($active, $ids));
        }
        foreach (['material', 'note'] as $field) {
            if (array_key_exists($field, $data)) $fields[$field] = self::text($data[$field]);
        }
        if (array_key_exists('thickness', $data)) $fields['thickness'] = $data['thickness'] === null || $data['thickness'] === '' ? null : round((float) $data['thickness'], 2);
        // A price-list thickness names the material and thickness itself.
        if (array_key_exists('pricing_thickness_id', $data)) {
            $fields['pricing_thickness_id'] = null;
            if ($data['pricing_thickness_id'] !== null) {
                $choice = PricingThickness::with('material')->where('is_active', true)->find((int) $data['pricing_thickness_id']);
                if (!$choice || !$choice->material?->is_active) {
                    throw ValidationException::withMessages(['pricing_thickness_id' => ['Ընտրեք ցանկի գործող նյութն ու հաստությունը։']]);
                }
                $fields['pricing_thickness_id'] = $choice->id;
                $fields['material'] = $choice->material->name;
                $fields['thickness'] = $choice->thickness;
            }
        }
        if (array_key_exists('bends', $data)) $fields['bends'] = $data['bends'] === null || $data['bends'] === '' ? null : (int) $data['bends'];
        if (array_key_exists('quantity', $data)) $fields['quantity'] = (int) $data['quantity'];
        if (array_key_exists('paint_ral', $data)) $fields['paint_ral'] = TaskPaint::ral($data['paint_ral']);
        if (array_key_exists('primer_required', $data)) $fields['primer_required'] = (bool) $data['primer_required'];
        // Colour and primer only mean something when powder coating is wanted.
        $chosen = $fields['workshop_ids'] ?? ($item?->workshop_ids ?? []);
        if (!self::workshops()->whereIn('id', $chosen)->contains(fn (Factory $factory) => TaskPaint::isPowder($factory))) {
            $fields['paint_ral'] = null;
            $fields['primer_required'] = false;
        }
        return $fields;
    }

    /** What the drawing measures; files other than DXF are priced by the manager. */
    private static function measure(string $stored, string $extension): array
    {
        if ($extension !== 'dxf') return ['status' => 'unsupported'];
        try {
            return ['status' => 'ok'] + DxfMeasure::file(Storage::disk('private')->path($stored));
        } catch (\Throwable $e) {
            return ['status' => 'failed'];
        }
    }

    private static function draftItem(User $customer, int $itemId): QuoteRequestItem
    {
        $item = QuoteRequestItem::whereKey($itemId)->lockForUpdate()->firstOrFail();
        $quote = QuoteRequest::whereKey($item->quote_request_id)->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();
        abort_unless($quote->status === 'draft', 422, 'Ուղարկված հայտի ֆայլերը փոխել հնարավոր չէ։');
        return $item;
    }

    private static function transition(QuoteRequest $quote, array $from, string $to, callable $fields, string $message, ?User $customer = null): QuoteRequest
    {
        return DB::transaction(function () use ($quote, $from, $to, $fields, $message, $customer) {
            $locked = QuoteRequest::whereKey($quote->id)
                ->when($customer, fn ($q) => $q->where('customer_id', $customer->id))
                ->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, $from, true), 422, $message);
            $locked->update(['status' => $to] + $fields());
            return $locked;
        }, 3);
    }

    // One customer's draft is created and sent one request at a time.
    private static function lockCustomer(User $customer): void
    {
        CompanyMembership::where('company_id', app(CompanyContext::class)->id())->where('user_id', $customer->id)->lockForUpdate()->first();
    }

    private static function nextNumber(): string
    {
        $companyId = app(CompanyContext::class)->id();
        $year = (int) now('Asia/Yerevan')->format('Y');
        DB::table('quote_sequences')->insertOrIgnore(['company_id' => $companyId, 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('quote_sequences')->where('company_id', $companyId)->where('year', $year)->lockForUpdate()->first();
        $next = (int) $row->last_number + 1;
        DB::table('quote_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);
        return sprintf('Q-%d-%04d', $year, $next);
    }

    private static function directory(User $customer): string
    {
        return 'companies/'.app(CompanyContext::class)->id().'/quotes/'.$customer->id;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function person(User $user): string
    {
        return trim($user->name.' '.($user->last_name ?? '')) ?: (string) $user->email;
    }

    private static function notifyStaff(User $actor, QuoteRequest $quote, string $type, string $message): void
    {
        foreach (User::query()->forRoles(self::STAFF_ROLES)->pluck('users.id') as $id) self::notify((int) $id, $actor, $quote, $type, $message);
    }

    private static function notify(int $recipientId, User $actor, QuoteRequest $quote, string $type, string $message): void
    {
        if ($recipientId === (int) $actor->id) return;
        try {
            TaskNotification::create([
                'user_id' => $recipientId, 'actor_id' => $actor->id, 'type' => $type, 'message' => mb_substr($message, 0, 1000),
                'data' => array_filter(['quote_id' => $quote->id, 'quote_number' => $quote->number, 'actor' => self::person($actor),
                    'price' => $type === 'quote.quoted' ? $quote->price : null], fn ($value) => $value !== null && $value !== ''),
            ]);
        } catch (\Throwable $e) {
            // A lost notice must never undo the request itself.
            Log::warning('Quote notification was not stored', ['type' => $type, 'quote_id' => $quote->id, 'exception' => $e]);
        }
    }
}
