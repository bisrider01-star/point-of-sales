<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseStockController extends Controller
{
    public function index(Request $request, OutletAccessService $access): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'warehouse_id' => ['nullable', 'integer'],
            'low_stock' => ['nullable', 'boolean'],
        ]);
        $warehouses = $access->warehousesFor($request->user());
        if ($request->filled('warehouse_id')) {
            abort_unless($warehouses->contains('id', (int) $filters['warehouse_id']), 403);
        }
        $visible = $warehouses->when($request->filled('warehouse_id'), fn ($items) => $items->where('id', (int) $filters['warehouse_id']))->values();
        $ids = $visible->pluck('id');
        $products = Product::query()
            ->where('is_composite', false)
            ->whereHas('warehouses', fn ($query) => $query->whereIn('warehouses.id', $ids))
            ->with(['category:id,name', 'warehouses' => fn ($query) => $query->whereIn('warehouses.id', $ids)])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            }))
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))
            ->when($request->boolean('low_stock'), fn ($query) => $query->where('min_stock', '>', 0)
                ->whereHas('warehouses', fn ($query) => $query->whereIn('warehouses.id', $ids)
                    ->whereColumn('product_warehouse.stock', '<=', 'products.min_stock')))
            ->orderBy('title')
            ->paginate($this->perPage())->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'category' => $product->category?->name,
                'min_stock' => $product->min_stock,
                'is_sellable' => $product->is_sellable,
                'stocks' => $product->warehouses->mapWithKeys(fn ($warehouse) => [$warehouse->id => (int) $warehouse->pivot->stock]),
                'total' => (int) $product->warehouses->sum('pivot.stock'),
            ]);

        return Inertia::render('Dashboard/WarehouseStocks/Index', [
            'products' => $products,
            'warehouses' => $warehouses,
            'visibleWarehouses' => $visible,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }
}
