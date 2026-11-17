<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WarehouseStockTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    public function test_it_lists_current_stock_per_warehouse(): void
    {
        $user = $this->user();
        $kitchen = $this->warehouse('DPR', 'Dapur');
        $store = $this->warehouse('TKO', 'Toko');
        $milk = $this->product('Susu', 2);
        $milk->warehouses()->attach([$kitchen->id => ['stock' => 40], $store->id => ['stock' => 2]]);

        $this->actingAs($user)->get(route('warehouse-stocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/WarehouseStocks/Index')
                ->has('visibleWarehouses', 2)
                ->where('products.data.0.title', 'Susu')
                ->where("products.data.0.stocks.{$kitchen->id}", 40)
                ->where("products.data.0.stocks.{$store->id}", 2)
                ->where('products.data.0.total', 42));
    }

    public function test_it_filters_warehouse_and_low_stock(): void
    {
        $user = $this->user();
        $kitchen = $this->warehouse('DPR', 'Dapur');
        $store = $this->warehouse('TKO', 'Toko');
        $milk = $this->product('Susu', 5);
        $sugar = $this->product('Gula', 5);
        $milk->warehouses()->attach([$kitchen->id => ['stock' => 40], $store->id => ['stock' => 3]]);
        $sugar->warehouses()->attach([$kitchen->id => ['stock' => 2], $store->id => ['stock' => 20]]);

        $this->actingAs($user)->get(route('warehouse-stocks.index', ['warehouse_id' => $store->id, 'low_stock' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('visibleWarehouses', 1)
                ->has('products.data', 1)
                ->where('products.data.0.title', 'Susu')
                ->where('products.data.0.total', 3));
    }

    public function test_it_does_not_expose_other_outlet_stock(): void
    {
        $user = $this->user();
        $outlet = Outlet::create(['code' => 'A', 'name' => 'Outlet A', 'is_active' => true]);
        $other = Outlet::create(['code' => 'B', 'name' => 'Outlet B', 'is_active' => true]);
        $user->outlets()->attach($outlet->id);
        $allowed = $this->warehouse('A', 'Gudang A');
        $allowed->update(['outlet_id' => $outlet->id]);
        $hidden = $this->warehouse('B', 'Gudang B');
        $hidden->update(['outlet_id' => $other->id]);
        $product = $this->product('Gelato', 0);
        $product->warehouses()->attach([$allowed->id => ['stock' => 3], $hidden->id => ['stock' => 100]]);

        $this->actingAs($user)->get(route('warehouse-stocks.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('warehouses', 1)
                ->has('products.data.0.stocks', 1)
                ->where('products.data.0.total', 3));
        $this->get(route('warehouse-stocks.index', ['warehouse_id' => $hidden->id]))->assertForbidden();
    }

    public function test_it_requires_products_access(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('warehouse-stocks.index'))
            ->assertForbidden();
    }

    private function user(): User
    {
        Permission::firstOrCreate(['name' => 'products-access', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('products-access');
        $user->markEmailAsVerified();

        return $user;
    }

    private function warehouse(string $code, string $name): Warehouse
    {
        return Warehouse::create(['code' => $code, 'name' => $name, 'type' => 'warehouse', 'is_active' => true, 'sort_order' => ++self::$seq]);
    }

    private function product(string $title, int $minStock): Product
    {
        $seq = ++self::$seq;
        $category = Category::create(['name' => "Kategori {$seq}", 'description' => 'Test', 'image' => 'category.png']);

        return Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => "WS-BAR-{$seq}",
            'sku' => "WS-SKU-{$seq}",
            'title' => $title,
            'description' => 'Test',
            'buy_price' => 1000,
            'sell_price' => 2000,
            'stock' => 0,
            'min_stock' => $minStock,
            'tax_rate' => 0,
        ]);
    }
}
