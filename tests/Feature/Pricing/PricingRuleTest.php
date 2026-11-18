<?php

namespace Tests\Feature\Pricing;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PricingRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'pricing-rules-access',
            'pricing-rules-create',
            'pricing-rules-update',
            'pricing-rules-delete',
            'transactions-access',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_authorized_user_can_create_pricing_rule(): void
    {
        $user = $this->createUserWithPermissions([
            'pricing-rules-access',
            'pricing-rules-create',
        ]);
        $category = Category::create([
            'name' => 'Minuman',
            'description' => 'Kategori uji',
            'image' => 'category.png',
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('pricing-rules.store'), [
                'name' => 'Promo Minuman Pagi',
                'kind' => PricingRule::KIND_STANDARD_DISCOUNT,
                'is_active' => true,
                'priority' => 120,
                'target_type' => 'category',
                'category_id' => $category->id,
                'customer_scope' => 'all',
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'starts_at' => now()->subHour()->format('Y-m-d\TH:i'),
                'ends_at' => now()->addHour()->format('Y-m-d\TH:i'),
                'notes' => 'Promo aktif pagi ini',
            ]);

        $response->assertRedirect(route('pricing-rules.index'));
        $this->assertDatabaseHas('pricing_rules', [
            'name' => 'Promo Minuman Pagi',
            'target_type' => 'category',
            'category_id' => $category->id,
            'discount_type' => 'percentage',
            'customer_scope' => 'all',
            'created_by' => $user->id,
        ]);
    }

    public function test_pricing_preview_respects_customer_scope(): void
    {
        $cashier = $this->createUserWithPermissions([
            'transactions-access',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ]);
        $this->openShiftFor($cashier);
        $product = $this->createProduct();
        $customer = Customer::create([
            'name' => 'Registered Customer',
            'no_telp' => '62812345678',
            'address' => 'Jl. Uji Pelanggan',
        ]);

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $product->sell_price,
        ]);

        PricingRule::create([
            'name' => 'Harga Member',
            'is_active' => true,
            'priority' => 200,
            'target_type' => 'product',
            'product_id' => $product->id,
            'customer_scope' => 'registered',
            'discount_type' => 'fixed_amount',
            'discount_value' => 10000,
        ]);

        $walkInResponse = $this
            ->actingAs($cashier)
            ->postJson(route('transactions.pricing-preview'), []);

        $registeredResponse = $this
            ->actingAs($cashier)
            ->postJson(route('transactions.pricing-preview'), [
                'customer_id' => $customer->id,
            ]);

        $walkInResponse->assertOk();
        $registeredResponse->assertOk();
        $this->assertSame(
            0,
            data_get($walkInResponse->json(), 'data.summary.promo_discount_total')
        );
        $this->assertSame(
            10000,
            data_get($registeredResponse->json(), 'data.summary.promo_discount_total')
        );
    }

    public function test_qty_break_preview_applies_wholesale_rule(): void
    {
        $cashier = $this->createUserWithPermissions([
            'transactions-access',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ]);
        $this->openShiftFor($cashier);
        $product = $this->createProduct();

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 3,
            'price' => $product->sell_price * 3,
        ]);

        $rule = PricingRule::create([
            'name' => 'Harga Grosir Produk',
            'kind' => PricingRule::KIND_QTY_BREAK,
            'is_active' => true,
            'priority' => 250,
            'target_type' => 'product',
            'product_id' => $product->id,
            'customer_scope' => 'all',
            'discount_type' => 'fixed_price',
            'discount_value' => 0,
        ]);
        $rule->qtyBreaks()->create([
            'min_qty' => 3,
            'discount_type' => 'fixed_price',
            'discount_value' => 50000,
            'sort_order' => 0,
        ]);

        $response = $this
            ->actingAs($cashier)
            ->postJson(route('transactions.pricing-preview'), []);

        $response->assertOk();
        $this->assertSame(
            30000,
            data_get($response->json(), 'data.summary.promo_discount_total')
        );
        $this->assertSame(
            'qty_break',
            data_get($response->json(), 'data.items.0.pricing_rule.kind')
        );
    }

    public function test_bundle_price_preview_returns_applied_group(): void
    {
        $cashier = $this->createUserWithPermissions([
            'transactions-access',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ]);
        $this->openShiftFor($cashier);
        $productA = $this->createProduct('Produk Bundle A');
        $productB = $this->createProduct('Produk Bundle B');

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $productA->id,
            'qty' => 1,
            'price' => $productA->sell_price,
        ]);
        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $productB->id,
            'qty' => 1,
            'price' => $productB->sell_price,
        ]);

        $rule = PricingRule::create([
            'name' => 'Bundle Hemat',
            'kind' => PricingRule::KIND_BUNDLE_PRICE,
            'is_active' => true,
            'priority' => 400,
            'target_type' => 'all',
            'customer_scope' => 'all',
            'discount_type' => 'fixed_price',
            'discount_value' => 100000,
        ]);
        $rule->bundleItems()->createMany([
            ['product_id' => $productA->id, 'quantity' => 1, 'sort_order' => 0],
            ['product_id' => $productB->id, 'quantity' => 1, 'sort_order' => 1],
        ]);

        $response = $this
            ->actingAs($cashier)
            ->postJson(route('transactions.pricing-preview'), []);

        $response->assertOk();
        $this->assertSame(
            20000,
            data_get($response->json(), 'data.summary.promo_discount_total')
        );
        $this->assertCount(1, data_get($response->json(), 'data.applied_groups', []));
    }

    public function test_buy_x_get_y_preview_discounts_reward_item(): void
    {
        $cashier = $this->createUserWithPermissions([
            'transactions-access',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ]);
        $this->openShiftFor($cashier);
        $buyProduct = $this->createProduct('Produk Buy');
        $getProduct = $this->createProduct('Produk Get');

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $buyProduct->id,
            'qty' => 1,
            'price' => $buyProduct->sell_price,
        ]);
        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $getProduct->id,
            'qty' => 1,
            'price' => $getProduct->sell_price,
        ]);

        $rule = PricingRule::create([
            'name' => 'Buy 1 Get 1',
            'kind' => PricingRule::KIND_BUY_X_GET_Y,
            'is_active' => true,
            'priority' => 450,
            'target_type' => 'all',
            'customer_scope' => 'all',
            'discount_type' => 'fixed_amount',
            'discount_value' => 0,
        ]);
        $rule->buyGetItems()->createMany([
            ['product_id' => $buyProduct->id, 'role' => 'buy', 'quantity' => 1, 'sort_order' => 0],
            ['product_id' => $getProduct->id, 'role' => 'get', 'quantity' => 1, 'sort_order' => 1],
        ]);

        $response = $this
            ->actingAs($cashier)
            ->postJson(route('transactions.pricing-preview'), []);

        $response->assertOk();
        $this->assertSame(
            60000,
            data_get($response->json(), 'data.summary.promo_discount_total')
        );
        $this->assertSame(
            'buy_x_get_y',
            data_get($response->json(), 'data.items.1.pricing_rule.kind')
        );
    }

    public function test_transaction_checkout_recalculates_grand_total_using_pricing_rules(): void
    {
        $cashier = $this->createUserWithPermissions([
            'transactions-access',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ]);
        $shift = $this->openShiftFor($cashier);
        $product = $this->createProduct();
        $customer = Customer::create([
            'name' => 'Customer Promo',
            'no_telp' => '628777888999',
            'address' => 'Jl. Promo No. 1',
        ]);

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 2,
            'price' => $product->sell_price * 2,
        ]);

        PricingRule::create([
            'name' => 'Harga Spesial Produk',
            'is_active' => true,
            'priority' => 300,
            'target_type' => 'product',
            'product_id' => $product->id,
            'customer_scope' => 'all',
            'discount_type' => 'fixed_price',
            'discount_value' => 50000,
        ]);

        $response = $this
            ->actingAs($cashier)
            ->post(route('transactions.store'), [
                'customer_id' => $customer->id,
                'discount' => 5000,
                'shipping_cost' => 0,
                'grand_total' => 999999,
                'cash' => 100000,
                'change' => 0,
            ]);

        $transaction = Transaction::with(['details', 'profits'])->latest('id')->first();

        $response->assertRedirect(route('transactions.print', $transaction->invoice));
        $this->assertNotNull($transaction);
        $this->assertSame($shift->id, $transaction->cashier_shift_id);
        $this->assertSame(95000, (int) $transaction->grand_total);
        $this->assertSame(5000, (int) $transaction->discount);
        $this->assertSame(100000, (int) $transaction->cash);
        $this->assertSame(5000, (int) $transaction->change);

        $detail = $transaction->details->first();
        $this->assertSame(60000, (int) $detail->base_unit_price);
        $this->assertSame(50000, (int) $detail->unit_price);
        $this->assertSame(100000, (int) $detail->price);
        $this->assertSame(20000, (int) $detail->discount_total);
        $this->assertSame('Harga Spesial Produk', $detail->pricing_rule_name);

        $profit = $transaction->profits->first();
        $this->assertSame(5000, (int) $profit->total);
        $this->assertDatabaseMissing('carts', [
            'cashier_id' => $cashier->id,
        ]);
        $this->assertSame(23, $product->fresh()->stock);
    }

    public function test_standard_discount_saves_when_form_posts_unused_item_lists(): void
    {
        $user = $this->createUserWithPermissions(['pricing-rules-access', 'pricing-rules-create']);

        $this->actingAs($user)
            ->post(route('pricing-rules.store'), $this->formPayload([
                'name' => 'Diskon Semua Produk',
                'discount_type' => 'percentage',
                'discount_value' => '10',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pricing-rules.index'));

        $rule = PricingRule::where('name', 'Diskon Semua Produk')->firstOrFail();
        $this->assertSame(PricingRule::KIND_STANDARD_DISCOUNT, $rule->kind);
        $this->assertSame(0, $rule->qtyBreaks()->count());
        $this->assertSame(0, $rule->bundleItems()->count());
        $this->assertSame(0, $rule->buyGetItems()->count());
    }

    public function test_qty_break_saves_when_form_posts_unused_item_lists(): void
    {
        $user = $this->createUserWithPermissions(['pricing-rules-access', 'pricing-rules-create']);

        $this->actingAs($user)
            ->post(route('pricing-rules.store'), $this->formPayload([
                'name' => 'Grosir Tiga',
                'kind' => PricingRule::KIND_QTY_BREAK,
                'discount_value' => '1',
                'qty_breaks' => [
                    ['min_qty' => '3', 'discount_type' => 'fixed_price', 'discount_value' => '50000', 'sort_order' => '0'],
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pricing-rules.index'));

        $rule = PricingRule::where('name', 'Grosir Tiga')->firstOrFail();
        $this->assertSame(1, $rule->qtyBreaks()->count());
        $this->assertSame(0, $rule->bundleItems()->count());
    }

    public function test_bundle_price_saves_when_form_posts_unused_item_lists(): void
    {
        $user = $this->createUserWithPermissions(['pricing-rules-access', 'pricing-rules-create']);
        $productA = $this->createProduct('Bundle Form A');
        $productB = $this->createProduct('Bundle Form B');

        $this->actingAs($user)
            ->post(route('pricing-rules.store'), $this->formPayload([
                'name' => 'Paket Hemat',
                'kind' => PricingRule::KIND_BUNDLE_PRICE,
                'discount_value' => '100000',
                'bundle_items' => [
                    ['product_id' => (string) $productA->id, 'quantity' => '1', 'sort_order' => '0'],
                    ['product_id' => (string) $productB->id, 'quantity' => '1', 'sort_order' => '1'],
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pricing-rules.index'));

        $rule = PricingRule::where('name', 'Paket Hemat')->firstOrFail();
        $this->assertSame(PricingRule::TYPE_FIXED_PRICE, $rule->discount_type);
        $this->assertSame(2, $rule->bundleItems()->count());
        $this->assertSame(0, $rule->qtyBreaks()->count());
    }

    public function test_buy_x_get_y_saves_without_discount_value(): void
    {
        $user = $this->createUserWithPermissions(['pricing-rules-access', 'pricing-rules-create']);
        $buyProduct = $this->createProduct('Beli Form');
        $getProduct = $this->createProduct('Gratis Form');

        $this->actingAs($user)
            ->post(route('pricing-rules.store'), $this->formPayload([
                'name' => 'Beli 1 Gratis 1',
                'kind' => PricingRule::KIND_BUY_X_GET_Y,
                'discount_value' => '',
                'buy_get_items' => [
                    ['product_id' => (string) $buyProduct->id, 'role' => 'buy', 'quantity' => '1', 'sort_order' => '0'],
                    ['product_id' => (string) $getProduct->id, 'role' => 'get', 'quantity' => '1', 'sort_order' => '1'],
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('pricing-rules.index'));

        $rule = PricingRule::where('name', 'Beli 1 Gratis 1')->firstOrFail();
        $this->assertSame(0.0, $rule->discount_value);
        $this->assertSame(2, $rule->buyGetItems()->count());
    }

    public function test_schedule_sent_as_utc_instant_is_active_inside_local_window(): void
    {
        $user = $this->createUserWithPermissions(['pricing-rules-access', 'pricing-rules-create']);
        $product = $this->createProduct('Gelato Coklat');

        // Browser in WIB picks 12:00-12:59 today; the form converts it to UTC instants (05:00Z-05:59Z).
        $this->travelTo(Carbon::parse('2026-11-18 05:52:00', 'UTC'));

        foreach (['UTC', 'Asia/Jakarta'] as $timezone) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);

            $this->actingAs($user)
                ->post(route('pricing-rules.store'), $this->formPayload([
                    'name' => 'Happy Hour '.$timezone,
                    'target_type' => 'product',
                    'product_id' => (string) $product->id,
                    'discount_type' => 'fixed_price',
                    'discount_value' => '4000',
                    'starts_at' => '2026-11-18T05:00:00.000Z',
                    'ends_at' => '2026-11-18T05:59:00.000Z',
                ]))
                ->assertRedirect(route('pricing-rules.index'));

            $rule = PricingRule::where('name', 'Happy Hour '.$timezone)->firstOrFail();

            $this->assertSame('2026-11-18T05:00:00+00:00', $rule->starts_at->utc()->toIso8601String(), $timezone);
            $this->assertSame('active', $rule->currentStatusLabel(), $timezone);
        }

        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    public function test_draft_preview_applies_category_fixed_price_to_each_sample_product(): void
    {
        $user = $this->createUserWithPermissions(['pricing-rules-access']);
        $first = $this->createProduct('Gelato Coklat');
        $second = $this->createProduct('Gelato Vanila');
        $second->update(['category_id' => $first->category_id]);

        $response = $this->actingAs($user)
            ->postJson(route('pricing-rules.preview'), $this->formPayload([
                'name' => 'Happy Hour',
                'target_type' => 'category',
                'category_id' => (string) $first->category_id,
                'discount_type' => 'fixed_price',
                'discount_value' => '4000',
            ]));

        $response->assertOk();
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(120000, $response->json('data.summary.base_subtotal'));
        $this->assertSame(112000, $response->json('data.summary.promo_discount_total'));
        $this->assertSame(8000, $response->json('data.summary.subtotal_after_promo'));
    }

    public function test_bundle_with_only_one_product_is_rejected(): void
    {
        $user = $this->createUserWithPermissions(['pricing-rules-access', 'pricing-rules-create']);
        $product = $this->createProduct('Single Bundle');

        $this->actingAs($user)
            ->post(route('pricing-rules.store'), $this->formPayload([
                'name' => 'Single Bundle',
                'kind' => PricingRule::KIND_BUNDLE_PRICE,
                'discount_type' => 'fixed_price',
                'discount_value' => '50000',
                'bundle_items' => [
                    ['product_id' => $product->id, 'quantity' => '1', 'sort_order' => '0'],
                ],
            ]))
            ->assertSessionHasErrors('bundle_items');

        $this->assertDatabaseMissing('pricing_rules', ['name' => 'Single Bundle']);
    }

    public function test_incomplete_items_for_selected_kind_are_still_rejected(): void
    {
        $user = $this->createUserWithPermissions(['pricing-rules-access', 'pricing-rules-create']);
        $product = $this->createProduct('Bundle Kurang');

        $this->actingAs($user)
            ->post(route('pricing-rules.store'), $this->formPayload([
                'name' => 'Bundle Tidak Lengkap',
                'kind' => PricingRule::KIND_BUNDLE_PRICE,
                'discount_value' => '100000',
                'bundle_items' => [
                    ['product_id' => (string) $product->id, 'quantity' => '1', 'sort_order' => '0'],
                    ['product_id' => '', 'quantity' => '1', 'sort_order' => '1'],
                ],
            ]))
            ->assertSessionHasErrors('bundle_items.1.product_id');

        $this->assertDatabaseMissing('pricing_rules', ['name' => 'Bundle Tidak Lengkap']);
    }

    public function test_mix_match_bundles_pool_quantities_and_follow_priority(): void
    {
        $user = $this->createUserWithPermissions(['pricing-rules-access', 'pricing-rules-create']);
        $user->markEmailAsVerified();
        $products = collect(range(1, 5))->map(function (int $index) {
            $product = $this->createProduct('Porsi '.$index);
            $product->update(['sell_price' => 39000]);

            return $product;
        });
        $bundleItems = $products->take(4)->map(fn (Product $product) => ['product_id' => $product->id])->all();

        foreach ([2 => 75000, 3 => 109000] as $qty => $price) {
            $payload = $this->formPayload([
                'name' => 'Mix & Match '.$qty,
                'kind' => PricingRule::KIND_BUNDLE_PRICE,
                'discount_type' => PricingRule::TYPE_FIXED_PRICE,
                'discount_value' => $price,
                'priority' => $qty * 100,
                'mix_match_total_qty' => $qty,
                'bundle_items' => $bundleItems,
            ]);
            $this->actingAs($user)->post(route('pricing-rules.store'), $payload)
                ->assertSessionHasNoErrors()->assertRedirect(route('pricing-rules.index'));
            $this->postJson(route('pricing-rules.preview'), $payload)
                ->assertOk()->assertJsonPath('data.summary.subtotal_after_promo', $price);
        }

        $scenarios = [
            [[1], 39000, 0],
            [[2], 75000, 1],
            [[1, 1], 75000, 1],
            [[3], 109000, 1],
            [[1, 1, 1], 109000, 1],
            [[1, 1, 1, 1], 148000, 1],
            [[2, 1, 1, 1], 184000, 2],
            [[2, 2, 1, 1], 218000, 2],
            [[1, 0, 0, 0, 1], 78000, 0],
        ];

        foreach ($scenarios as [$quantities, $total, $groups]) {
            $carts = collect($quantities)->filter()->map(function (int $qty, int $index) use ($products) {
                $cart = new Cart(['product_id' => $products[$index]->id, 'qty' => $qty, 'price' => $qty * 39000]);
                $cart->id = $index + 1;
                $cart->setRelation('product', $products[$index]);

                return $cart;
            });
            $preview = app(PricingService::class)->previewCart($carts);
            $this->assertSame($total, $preview['summary']['subtotal_after_promo']);
            $this->assertSame($total, array_sum(array_column($preview['items'], 'line_total')));
            $this->assertCount($groups, $preview['applied_groups']);
        }
    }

    public function test_bundle_preview_accepts_create_form_defaults(): void
    {
        $user = $this->createUserWithPermissions(['pricing-rules-access']);
        $productA = $this->createProduct('Preview Default A');
        $productB = $this->createProduct('Preview Default B');

        $this->actingAs($user)
            ->postJson(route('pricing-rules.preview'), $this->formPayload([
                'name' => '',
                'kind' => PricingRule::KIND_BUNDLE_PRICE,
                'discount_value' => '75000',
                'mix_match_total_qty' => '2',
                'bundle_items' => [
                    ['product_id' => (string) $productA->id, 'quantity' => '1', 'sort_order' => '0'],
                    ['product_id' => (string) $productB->id, 'quantity' => '1', 'sort_order' => '1'],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.summary.subtotal_after_promo', 75000);
    }

    /**
     * Mirrors the create form, which always posts every item list with its blank default rows.
     */
    private function formPayload(array $overrides): array
    {
        return array_merge([
            'name' => 'Promo Form',
            'kind' => PricingRule::KIND_STANDARD_DISCOUNT,
            'is_active' => true,
            'priority' => '100',
            'target_type' => 'all',
            'product_id' => '',
            'category_id' => '',
            'customer_scope' => 'all',
            'eligible_loyalty_tiers' => [],
            'discount_type' => 'percentage',
            'discount_value' => '',
            'preview_quantity_multiplier' => '1',
            'starts_at' => '',
            'ends_at' => '',
            'notes' => '',
            'qty_breaks' => [
                ['min_qty' => '3', 'discount_type' => 'fixed_price', 'discount_value' => '', 'sort_order' => '0'],
            ],
            'bundle_items' => [
                ['product_id' => '', 'quantity' => '1', 'sort_order' => '0'],
                ['product_id' => '', 'quantity' => '1', 'sort_order' => '1'],
            ],
            'buy_get_items' => [
                ['product_id' => '', 'role' => 'buy', 'quantity' => '1', 'sort_order' => '0'],
                ['product_id' => '', 'role' => 'get', 'quantity' => '1', 'sort_order' => '1'],
            ],
        ], $overrides);
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function openShiftFor(User $cashier)
    {
        return CashierShift::create([
            'user_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'status' => 'open',
        ]);
    }

    private function createProduct(?string $title = null): Product
    {
        $category = Category::create([
            'name' => 'Snack Promo '.Str::upper(Str::random(4)),
            'description' => 'Kategori promo',
            'image' => 'category.png',
        ]);

        return Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'title' => $title ?? 'Produk Promo',
            'description' => 'Produk untuk pengujian promo.',
            'buy_price' => 45000,
            'sell_price' => 60000,
            'stock' => 25,
            'tax_rate' => 0,
        ]);
    }
}
