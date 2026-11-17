<?php

namespace Tests\Feature\Products;

use App\Models\Cart;
use App\Models\Category;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SellableProductTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private User $cashier;

    private Warehouse $warehouse;

    private Product $gelato;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access', 'products-access'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->cashier = User::factory()->create();
        $this->cashier->markEmailAsVerified();
        $this->cashier->givePermissionTo(['transactions-access', 'cashier-shifts-access', 'products-access']);

        $this->warehouse = Warehouse::create([
            'code' => 'TKO',
            'name' => 'Toko',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->gelato = $this->product('Gelato Coklat', true);
        $this->milk = $this->product('Susu Segar', false);

        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);
    }

    public function test_new_products_are_sellable_by_default(): void
    {
        $this->assertTrue($this->gelato->fresh()->is_sellable);
    }

    public function test_pos_page_hides_warehouse_only_products(): void
    {
        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('products', 1)
                ->where('products.0.title', 'Gelato Coklat'));
    }

    public function test_barcode_search_and_add_to_cart_reject_warehouse_only_products(): void
    {
        $this->actingAs($this->cashier)
            ->postJson(route('transactions.searchProduct'), ['barcode' => $this->milk->barcode])
            ->assertJsonPath('success', false);

        $this->actingAs($this->cashier)
            ->post(route('transactions.addToCart'), ['product_id' => $this->milk->id, 'qty' => 1])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, Cart::count());
    }

    public function test_checkout_rejects_existing_warehouse_only_cart_item(): void
    {
        PaymentSetting::create([
            'default_gateway' => 'qris_static',
            'cash_enabled' => false,
            'qris_static_enabled' => true,
        ]);
        Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->milk->id,
            'qty' => 1,
            'price' => $this->milk->sell_price,
        ]);

        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['payment_gateway' => 'qris_static'])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(10, (int) $this->milk->fresh()->stock);
    }

    public function test_pos_api_hides_and_rejects_warehouse_only_products(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);

        $this->getJson('/api/v1/pos/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Gelato Coklat');

        $this->postJson('/api/v1/pos/products/scan', ['barcode' => $this->milk->barcode])
            ->assertNotFound();

        $this->postJson('/api/v1/pos/cart', ['product_id' => $this->milk->id, 'qty' => 1])
            ->assertStatus(422);
    }

    public function test_warehouse_stock_still_lists_raw_materials(): void
    {
        $this->actingAs($this->cashier)->get(route('warehouse-stocks.index', ['search' => 'Susu']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.title', 'Susu Segar')
                ->where('products.data.0.is_sellable', false)
                ->where('products.data.0.total', 10));
    }

    private function product(string $title, bool $sellable): Product
    {
        $seq = ++self::$seq;
        $category = Category::create(['name' => "Kategori {$seq}", 'description' => 'Test', 'image' => 'category.png']);

        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => "SELL-BAR-{$seq}",
            'sku' => "SELL-SKU-{$seq}",
            'title' => $title,
            'description' => 'Test',
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 10,
            'tax_rate' => 0,
            ...($sellable ? [] : ['is_sellable' => false]),
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['stock' => 10]);

        return $product;
    }
}
