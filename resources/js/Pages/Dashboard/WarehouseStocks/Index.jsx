import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router } from "@inertiajs/react";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import { IconBuildingWarehouse } from "@tabler/icons-react";

const fieldClass =
    "h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200";

export default function Index({ products, warehouses, visibleWarehouses, categories, filters }) {
    const updateFilter = (key, value) =>
        router.get(route("warehouse-stocks.index"), { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });

    return (
        <>
            <Head title="Stok Gudang" />
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Stok Gudang</h1>
                <p className="text-sm text-slate-500 dark:text-slate-400">Stok saat ini untuk setiap produk di setiap gudang.</p>
            </div>

            <div className="mb-4 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-4">
                <input defaultValue={filters.search || ""} onKeyDown={(e) => e.key === "Enter" && updateFilter("search", e.currentTarget.value)} placeholder="Cari produk, SKU, barcode lalu Enter" className={fieldClass} />
                <select value={filters.category_id || ""} onChange={(e) => updateFilter("category_id", e.target.value)} className={fieldClass}>
                    <option value="">Semua Kategori</option>
                    {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                </select>
                <select value={filters.warehouse_id || ""} onChange={(e) => updateFilter("warehouse_id", e.target.value)} className={fieldClass}>
                    <option value="">Semua Gudang</option>
                    {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                </select>
                <label className="flex h-11 items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                    <input type="checkbox" checked={Boolean(filters.low_stock)} onChange={(e) => updateFilter("low_stock", e.target.checked ? 1 : "")} className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" />
                    Hanya stok rendah
                </label>
            </div>

            <p className="mb-4 text-xs text-slate-500 dark:text-slate-400">
                Total hanya mencakup gudang yang ditampilkan. Stok rendah jika stok di salah satu gudang mencapai batas minimum produk. Produk komposit tidak memiliki stok sendiri dan tidak ditampilkan.
            </p>
            <Table.Card title="Stok per Gudang">
                <Table>
                    <Table.Thead>
                        <tr>
                            <Table.Th>Produk</Table.Th>
                            <Table.Th>Kategori</Table.Th>
                            {visibleWarehouses.map((warehouse) => <Table.Th key={warehouse.id} className="text-right">{warehouse.name}</Table.Th>)}
                            <Table.Th className="text-right">Total</Table.Th>
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {products.data.length ? products.data.map((product) => (
                            <tr key={product.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <Table.Td>
                                    <p className="font-medium text-slate-800 dark:text-slate-200">
                                        {product.title}
                                        {!product.is_sellable && (
                                            <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                                                Bahan baku
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-xs text-slate-500">{product.sku || product.barcode || "-"}</p>
                                </Table.Td>
                                <Table.Td>{product.category || "-"}</Table.Td>
                                {visibleWarehouses.map((warehouse) => {
                                    const stock = product.stocks[warehouse.id] ?? 0;
                                    const low = product.min_stock > 0 && stock <= product.min_stock;
                                    return <Table.Td key={warehouse.id} className={`text-right font-semibold ${low ? "text-danger-600" : "text-slate-700 dark:text-slate-300"}`}>{stock}</Table.Td>;
                                })}
                                <Table.Td className="text-right font-bold">{product.total}</Table.Td>
                            </tr>
                        )) : (
                            <Table.Empty colSpan={visibleWarehouses.length + 3} message="Belum ada stok produk.">
                                <IconBuildingWarehouse size={28} className="mx-auto mb-3 text-slate-400" />
                            </Table.Empty>
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>
            {products.last_page > 1 && <Pagination links={products.links} />}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
