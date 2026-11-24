<?php

namespace Tests\Feature;

use App\Models\{Client, Company, CompanyMembership, Factory, Permission, QuoteRequest, Role, TaskNotification, User};
use App\Support\CompanyContext;
use Database\Seeders\{FactorySeeder, FactoryFileExtensionSeeder, FactoryOrderStatusSeeder, RoleTableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Mail, Schema, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerQuoteRequestsTest extends TestCase
{
    use RefreshDatabase;
    private Company $company;
    private Company $other;
    private User $manager;
    private User $customer;
    private array $factories;
    private bool $native = false;
    protected function beforeRefreshingDatabase(): void { $this->native = \Illuminate\Database\Schema\Builder::$alwaysUsesNativeSchemaOperationsIfPossible; if (DB::getDriverName() === 'mysql') Schema::useNativeSchemaOperationsIfPossible(); }
    protected function afterRefreshingDatabase(): void { Schema::useNativeSchemaOperationsIfPossible($this->native); }
    protected function setUp(): void
    {
        parent::setUp(); $this->seed(RoleTableSeeder::class);
        $this->company = Company::where('slug', 'metalworks')->firstOrFail();
        $this->other = Company::create(['name' => 'Private factory', 'slug' => 'private-factory']);
        foreach ([$this->company, $this->other] as $company) app(CompanyContext::class)->run($company, function () { (new FactorySeeder)->run(); (new FactoryFileExtensionSeeder)->run(); (new FactoryOrderStatusSeeder)->run(); });
        $this->factories = $this->in(fn () => Factory::get()->keyBy('value')->all());
        $this->manager = $this->account('manager');
        $this->customer = $this->customerAccount('Anna');
        Storage::fake('private'); Mail::fake();
    }

    public function test_a_customer_fills_one_draft_and_sends_it_to_the_company(): void
    {
        $this->as($this->customer);
        $options = $this->getJson('/api/customer/quotes/options', $this->headers())->assertOk()->json();
        $this->assertContains('DXF', array_column($options['workshops'], 'value'));
        $this->assertNotContains('SW', array_column($options['workshops'], 'value'));
        $this->assertContains('step', $options['extensions']);
        $this->assertTrue(collect($options['workshops'])->firstWhere('value', 'POWDER')['powder']);

        $draft = $this->postJson('/api/customer/quotes/draft/items', [
            'file' => UploadedFile::fake()->create('plate.dxf', 12), 'workshop_ids' => [$this->id('POWDER'), $this->id('DXF')],
            'material' => ' St37 ', 'thickness' => '2', 'quantity' => 5, 'paint_ral' => '9005', 'primer_required' => true,
        ], $this->headers())->assertCreated()->json('draft');
        $item = $draft['items'][0];
        $this->assertSame('draft', $draft['status']);
        $this->assertSame(collect([$this->id('POWDER'), $this->id('DXF')])->sort()->values()->all(), $item['workshop_ids'], 'kept in the company order of services');
        $this->assertSame(['St37', 'RAL 9005', true, 5], [$item['material'], $item['paint_ral'], $item['primer_required'], $item['quantity']]);
        $this->assertArrayNotHasKey('path', $item);
        $stored = DB::table('quote_request_items')->where('id', $item['id'])->value('path');
        $this->assertStringStartsWith('companies/'.$this->company->id.'/quotes/'.$this->customer->id.'/', $stored);
        Storage::disk('private')->assertExists($stored);

        // Without powder coating a colour is not kept.
        $tube = $this->postJson('/api/customer/quotes/draft/items', [
            'file' => UploadedFile::fake()->create('tube.step', 20), 'workshop_ids' => [$this->id('IQS')], 'paint_ral' => '3020',
        ], $this->headers())->assertCreated();
        $this->assertSame($draft['id'], $tube->json('draft.id'), 'one draft at a time');
        $tubeItem = collect($tube->json('draft.items'))->firstWhere('original_name', 'tube.step');
        $this->assertNull($tubeItem['paint_ral']);
        $this->patchJson('/api/customer/quotes/draft/items/'.$item['id'], ['workshop_ids' => [$this->id('DXF')], 'quantity' => 10], $this->headers())
            ->assertOk()->assertJsonPath('draft.items.0.paint_ral', null)->assertJsonPath('draft.items.0.primer_required', false)->assertJsonPath('draft.items.0.quantity', 10);
        $this->patchJson('/api/customer/quotes/draft/items/'.$item['id'], ['workshop_ids' => [$this->id('SW')]], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('workshop_ids');
        $this->postJson('/api/customer/quotes/draft/items', ['file' => UploadedFile::fake()->create('setup.exe', 5)], $this->headers())->assertUnprocessable();
        $this->postJson('/api/customer/quotes/draft/items', ['file' => UploadedFile::fake()->create('readme.txt', 5)], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $tubePath = DB::table('quote_request_items')->where('id', $tubeItem['id'])->value('path');
        $this->deleteJson('/api/customer/quotes/draft/items/'.$tubeItem['id'], [], $this->headers())->assertOk()->assertJsonCount(1, 'draft.items');
        Storage::disk('private')->assertMissing($tubePath);

        $this->postJson('/api/customer/quotes/draft/submit', ['phone' => '+374 91 123456', 'desired_date' => '2020-01-01'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('desired_date');
        $quote = $this->postJson('/api/customer/quotes/draft/submit', ['title' => 'Gate plates', 'note' => 'Edges deburred', 'phone' => '+374 91 123456'], $this->headers())
            ->assertCreated()->assertJsonPath('quote.status', 'submitted')->json('quote');
        $this->assertMatchesRegularExpression('/^Q-\d{4}-0001$/', $quote['number']);
        $this->assertSame(1, $this->in(fn () => TaskNotification::where('user_id', $this->manager->id)->where('type', 'quote.submitted')->count()));
        $this->patchJson('/api/customer/quotes/draft/items/'.$item['id'], ['quantity' => 3], $this->headers())->assertUnprocessable();
        $this->postJson('/api/customer/quotes/draft/submit', [], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->getJson('/api/customer/quotes', $this->headers())->assertOk()->assertJsonPath('draft', null)->assertJsonCount(1, 'quotes')
            ->assertJsonPath('quotes.0.title', 'Gate plates');

        $this->postJson('/api/customer/quotes/draft/items', ['file' => UploadedFile::fake()->create('next.dxf', 3)], $this->headers())->assertCreated();
        $second = $this->postJson('/api/customer/quotes/draft/submit', [], $this->headers())->assertCreated()->json('quote.number');
        $this->assertMatchesRegularExpression('/^Q-\d{4}-0002$/', $second);
    }

    public function test_the_company_answers_with_a_price_and_the_customer_accepts_it(): void
    {
        $quote = $this->sentQuote();
        $this->as($this->manager);
        $this->getJson('/api/quote-requests', $this->headers())->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.customer.name', 'Anna Customer')->assertJsonPath('data.0.customer.phone', '+374 91 000000')
            ->assertJsonPath('counts.submitted', 1);
        $this->getJson('/api/quote-requests?q=anna', $this->headers())->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/quote-requests?q=nobody', $this->headers())->assertOk()->assertJsonPath('meta.total', 0);
        $item = $quote->items()->first();
        $this->get('/api/quote-requests/'.$quote->id.'/items/'.$item->id.'/file', $this->headers())->assertOk();
        $this->assertStringContainsString('attachment', $this->get('/api/quote-requests/'.$quote->id.'/items/'.$item->id.'/file?download=1', $this->headers())
            ->assertOk()->headers->get('content-disposition'));

        $this->postJson('/api/quote-requests/'.$quote->id.'/answer', ['ready_date' => '2026-12-20'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->postJson('/api/quote-requests/'.$quote->id.'/answer', ['price' => 150000, 'ready_date' => '2026-12-20', 'response_note' => 'Includes powder coating'], $this->headers())
            ->assertOk()->assertJsonPath('quote.status', 'quoted')->assertJsonPath('quote.price', '150000.00')->assertJsonPath('quote.ready_date', '2026-12-20');
        $notice = $this->in(fn () => TaskNotification::where('user_id', $this->customer->id)->where('type', 'quote.quoted')->first());
        $this->assertSame($quote->number, $notice->data['quote_number']);
        // The company may correct its answer until the customer decides.
        $this->postJson('/api/quote-requests/'.$quote->id.'/answer', ['price' => 140000], $this->headers())->assertOk()->assertJsonPath('quote.price', '140000.00');

        $this->as($this->customer);
        $this->postJson('/api/customer/quotes/'.$quote->id.'/cancel', [], $this->headers())->assertUnprocessable();
        $this->postJson('/api/customer/quotes/'.$quote->id.'/decision', ['decision' => 'maybe'], $this->headers())->assertUnprocessable();
        $this->postJson('/api/customer/quotes/'.$quote->id.'/decision', ['decision' => 'accept'], $this->headers())->assertOk()->assertJsonPath('quote.status', 'accepted');
        $this->assertSame(1, $this->in(fn () => TaskNotification::where('user_id', $this->manager->id)->where('type', 'quote.accepted')->count()));

        $this->as($this->manager);
        $this->postJson('/api/quote-requests/'.$quote->id.'/answer', ['price' => 1], $this->headers())->assertUnprocessable();
        $this->postJson('/api/quote-requests/'.$quote->id.'/reject', ['response_note' => 'Late'], $this->headers())->assertUnprocessable();
    }

    public function test_the_company_can_turn_a_request_down_and_the_customer_can_cancel_before_an_answer(): void
    {
        $cancelled = $this->sentQuote();
        $this->as($this->customer);
        $this->postJson('/api/customer/quotes/'.$cancelled->id.'/cancel', [], $this->headers())->assertOk()->assertJsonPath('quote.status', 'cancelled');
        $this->assertSame(1, $this->in(fn () => TaskNotification::where('user_id', $this->manager->id)->where('type', 'quote.cancelled')->count()));

        $rejected = $this->sentQuote();
        $this->as($this->manager);
        $this->postJson('/api/quote-requests/'.$rejected->id.'/reject', [], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('response_note');
        $this->postJson('/api/quote-requests/'.$rejected->id.'/reject', ['response_note' => 'We do not cut titanium'], $this->headers())
            ->assertOk()->assertJsonPath('quote.status', 'rejected')->assertJsonPath('quote.response_note', 'We do not cut titanium');
        $this->assertSame(1, $this->in(fn () => TaskNotification::where('user_id', $this->customer->id)->where('type', 'quote.rejected')->count()));
        $this->getJson('/api/quote-requests?status=rejected', $this->headers())->assertOk()->assertJsonPath('meta.total', 1);
        $this->as($this->customer);
        $this->postJson('/api/customer/quotes/'.$rejected->id.'/decision', ['decision' => 'accept'], $this->headers())->assertUnprocessable();
    }

    public function test_requests_stay_with_their_customer_and_their_company(): void
    {
        $quote = $this->sentQuote();
        $item = $quote->items()->first();
        $stranger = $this->customerAccount('Bob');
        $this->as($stranger);
        $this->getJson('/api/customer/quotes/'.$quote->id, $this->headers())->assertNotFound();
        $this->get('/api/customer/quotes/'.$quote->id.'/items/'.$item->id.'/file', $this->headers())->assertNotFound();
        $this->postJson('/api/customer/quotes/'.$quote->id.'/decision', ['decision' => 'accept'], $this->headers())->assertNotFound();
        $this->getJson('/api/customer/quotes', $this->headers())->assertOk()->assertJsonCount(0, 'quotes');

        $engineer = $this->account('engineer');
        $this->as($engineer);
        $this->getJson('/api/quote-requests', $this->headers())->assertForbidden();
        $this->getJson('/api/customer/quotes', $this->headers())->assertForbidden();

        // A customer whose access was switched off cannot send anything.
        $this->in(fn () => Client::where('user_id', $stranger->id)->update(['access_enabled' => false]));
        $this->as($stranger);
        $this->getJson('/api/customer/quotes/options', $this->headers())->assertForbidden();

        // An unsent draft stays the customer's own.
        $this->as($this->customer);
        $this->postJson('/api/customer/quotes/draft/items', ['file' => UploadedFile::fake()->create('draft.dxf', 3)], $this->headers())->assertCreated();
        $draft = $this->in(fn () => QuoteRequest::where('status', 'draft')->firstOrFail());
        $this->as($this->manager);
        $this->getJson('/api/quote-requests/'.$draft->id, $this->headers())->assertNotFound();
        $this->getJson('/api/quote-requests', $this->headers())->assertJsonPath('meta.total', 1);

        $foreign = app(CompanyContext::class)->run($this->other, fn () => QuoteRequest::create(['customer_id' => $this->manager->id, 'status' => 'submitted', 'number' => 'Q-2026-0001']));
        $this->getJson('/api/quote-requests/'.$foreign->id, $this->headers())->assertNotFound();
    }

    public function test_a_flat_drawing_gets_an_instant_estimate_that_is_kept_when_sent(): void
    {
        $prices = $this->priceList();
        $thickness = $prices['materials'][0]['thicknesses'][0]['id'];

        $this->as($this->customer);
        $this->assertSame($thickness, $this->getJson('/api/customer/quotes/options', $this->headers())->assertOk()->json('pricing.materials.0.thicknesses.0.id'));
        $plate = "0\nSECTION\n2\nHEADER\n9\n\$INSUNITS\n70\n4\n0\nENDSEC\n0\nSECTION\n2\nENTITIES\n"
            ."0\nLWPOLYLINE\n8\n0\n90\n4\n70\n1\n10\n0\n20\n0\n10\n100\n20\n0\n10\n100\n20\n50\n10\n0\n20\n50\n"
            ."0\nCIRCLE\n8\n0\n10\n50\n20\n25\n40\n5\n0\nENDSEC\n0\nEOF\n";
        $draft = $this->postJson('/api/customer/quotes/draft/items', ['file' => UploadedFile::fake()->createWithContent('plate.dxf', $plate)], $this->headers())
            ->assertCreated()->json('draft');
        $item = $draft['items'][0];
        $this->assertSame([$this->id('DXF')], $item['workshop_ids'], 'a flat drawing is laser cut by default');
        $this->assertSame(['ok', 2, 100.0], [$item['metrics']['status'], $item['metrics']['pierces'], (float) $item['metrics']['width_mm']]);
        $this->assertSame(['material'], $item['pricing']['reasons']);

        $draft = $this->patchJson('/api/customer/quotes/draft/items/'.$item['id'], [
            'pricing_thickness_id' => $thickness, 'quantity' => 10, 'bends' => 2,
            'workshop_ids' => [$this->id('DXF'), $this->id('DLD'), $this->id('POWDER')], 'paint_ral' => '9005',
        ], $this->headers())->assertOk()->json('draft');
        $price = $draft['items'][0]['pricing'];
        // material 0.0055 m² × 2 mm × 7850 kg/m³ × 600 = 51.8; cutting 0.3314 m × 400 + 2 × 50 = 232.6;
        // bending 2 × 500; painting the 500 minimum: 1784.4, less 10 % for ten parts.
        $this->assertSame(['priced', 1606, 16060, 10.0], [$price['status'], $price['unit'], $price['line'], (float) $price['discount_percent']]);
        $this->assertArrayNotHasKey('breakdown', $price, 'customers see the price, not how it is built');
        $this->assertSame([['quantity' => 1, 'unit' => 1784], ['quantity' => 10, 'unit' => 1606]], $price['breaks']);
        $this->assertSame(['St37', '2.00'], [$draft['items'][0]['material'], $draft['items'][0]['thickness']]);
        $this->assertSame([18060, true, 2000], [$draft['estimate']['total'], $draft['estimate']['complete'], $draft['estimate']['setup_fee']]);

        // A model file cannot be priced from the list: the estimate becomes partial.
        $model = $this->postJson('/api/customer/quotes/draft/items', ['file' => UploadedFile::fake()->create('frame.step', 4)], $this->headers())->assertCreated()->json('draft');
        $this->assertFalse($model['estimate']['complete']);
        $this->assertSame(18060, $model['estimate']['total']);
        $this->assertContains('drawing', collect($model['items'])->firstWhere('original_name', 'frame.step')['pricing']['reasons']);
        $this->deleteJson('/api/customer/quotes/draft/items/'.collect($model['items'])->firstWhere('original_name', 'frame.step')['id'], [], $this->headers())->assertOk();
        $sent = $this->postJson('/api/customer/quotes/draft/submit', [], $this->headers())->assertCreated()->json('quote');
        $this->assertSame(18060, $sent['estimate']['total']);

        // Later price changes leave the sent estimate as the customer saw it.
        $this->as($this->manager);
        $prices['materials'][0]['price_per_kg'] = 900;
        $this->putJson('/api/pricing', $prices, $this->headers())->assertOk()->assertJsonPath('materials.0.price_per_kg', 900);
        $staff = $this->getJson('/api/quote-requests/'.$sent['id'], $this->headers())->assertOk()->json('quote');
        $this->assertSame(18060, $staff['estimate']['total']);
        $this->assertSame(1000, $staff['items'][0]['pricing']['breakdown']['bending']);
        $this->as($this->account('engineer'));
        $this->getJson('/api/pricing', $this->headers())->assertForbidden();
    }

    public function test_a_drawing_whose_outline_does_not_close_is_left_to_the_manager(): void
    {
        $thickness = $this->priceList()['materials'][0]['thicknesses'][0]['id'];
        $this->as($this->customer);
        // A line and a hole, no outline around them.
        $lines = "0\nSECTION\n2\nENTITIES\n0\nLINE\n8\n0\n10\n0\n20\n0\n11\n100\n21\n0\n"
            ."0\nCIRCLE\n8\n0\n10\n50\n20\n20\n40\n10\n0\nENDSEC\n0\nEOF\n";
        $item = $this->postJson('/api/customer/quotes/draft/items', [
            'file' => UploadedFile::fake()->createWithContent('sketch.dxf', $lines), 'pricing_thickness_id' => $thickness,
        ], $this->headers())->assertCreated()->json('draft.items.0');
        $this->assertSame(['ok', 1, 2], [$item['metrics']['status'], $item['metrics']['open_contours'], $item['metrics']['pierces']]);
        $this->assertSame(['status' => 'on_request', 'reasons' => ['open']], $item['pricing']);
    }

    // The company's price list, switched on: St37 in 2 mm.
    private function priceList(): array
    {
        $this->as($this->manager);
        return $this->putJson('/api/pricing', [
            'settings' => ['enabled' => true, 'bend_price' => 500, 'paint_price_m2' => 3000, 'primer_price_m2' => 1000, 'paint_min_part' => 500,
                'setup_fee' => 2000, 'min_order' => 5000, 'waste_percent' => 10, 'discounts' => [['min_qty' => 10, 'percent' => 10]],
                'vat_included' => true, 'customer_note' => 'Includes VAT'],
            'materials' => [['name' => 'St37', 'density' => 7850, 'price_per_kg' => 600, 'is_active' => true,
                'thicknesses' => [['thickness' => 2, 'cut_price_per_m' => 400, 'pierce_price' => 50, 'is_active' => true]]]],
        ], $this->headers())->assertOk()->json();
    }

    private function sentQuote(): QuoteRequest
    {
        $this->as($this->customer);
        $this->postJson('/api/customer/quotes/draft/items', ['file' => UploadedFile::fake()->create('part.dxf', 8), 'workshop_ids' => [$this->id('DXF')], 'quantity' => 4], $this->headers())->assertCreated();
        $id = $this->postJson('/api/customer/quotes/draft/submit', ['phone' => '+374 91 000000'], $this->headers())->assertCreated()->json('quote.id');
        return $this->in(fn () => QuoteRequest::findOrFail($id));
    }

    private function id(string $value): int { return (int) $this->factories[$value]->id; }
    private function in(callable $callback): mixed { return app(CompanyContext::class)->run($this->company, $callback); }
    private function customerAccount(string $name): User
    {
        $user = $this->account('authenticatedUser', $name);
        $this->in(fn () => Client::create(['user_id' => $user->id, 'name' => $name, 'last_name' => 'Customer', 'phone' => '+374 91 000000', 'type' => 'physPerson', 'access_enabled' => true]));
        $this->in(fn () => $user->forceFill(['last_name' => 'Customer'])->saveQuietly());
        return $user;
    }
    private function account(string $role, ?string $name = null): User { return $this->in(function () use ($role, $name) { $user = User::create(['name' => $name ?? $role, 'email' => strtolower($name ?? $role).'-'.uniqid().'@example.invalid', 'password' => 'test-password', 'role_id' => Role::where('name', $role)->value('id')]); CompanyMembership::where('user_id', $user->id)->firstOrFail(); return $user; }); }
    private function as(User $user): void { app(CompanyContext::class)->set(null); $user->unsetRelation('role')->unsetRelation('factory')->unsetRelation('client'); Sanctum::actingAs($user); }
    private function headers(): array { return ['Accept' => 'application/json', 'X-Company-ID' => (string) $this->company->id]; }
}
