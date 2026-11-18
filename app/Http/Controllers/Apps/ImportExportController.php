<?php

namespace App\Http\Controllers\Apps;

use App\Exports\CustomersExport;
use App\Exports\ProductsExport;
use App\Exports\TransactionsExport;
use App\Http\Controllers\Controller;
use App\Imports\CustomersImport;
use App\Imports\ProductsImport;
use App\Models\Outlet;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Validators\ValidationException;

class ImportExportController extends Controller
{
    public function __construct(private readonly OutletAccessService $outletAccessService) {}

    public function exportProducts()
    {
        return Excel::download(new ProductsExport, 'produk.xlsx');
    }

    public function exportCustomers()
    {
        return Excel::download(new CustomersExport, 'customer.xlsx');
    }

    public function exportTransactions(Request $request)
    {
        $warehouseIds = $request->user()->isSuperAdmin()
            ? null
            : $this->outletAccessService->warehousesFor($request->user())->pluck('id')->all();
        $includeLegacy = Outlet::active()->count() <= 1;

        return Excel::download(new TransactionsExport($request, $warehouseIds, $includeLegacy), 'transaksi.xlsx');
    }

    public function importProducts(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $import = new ProductsImport;
        try {
            DB::transaction(fn () => Excel::import($import, $request->file('file')));
        } catch (ValidationException $exception) {
            $failure = $exception->failures()[0];

            return back()->withErrors(['file' => 'Baris '.$failure->row().': '.implode(' ', $failure->errors())]);
        }

        $successCount = $import->getRowCount();

        if ($successCount === 0) {
            return back()->withErrors(['file' => 'File tidak berisi baris produk.']);
        }

        return back()->with('success', "Import selesai. {$successCount} produk diimport.");
    }

    public function importCustomers(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $import = new CustomersImport;
        Excel::import($import, $request->file('file'));

        return back()->with('success', 'Import customer selesai.');
    }

    public function downloadTemplate(string $type)
    {
        $headings = match ($type) {
            'products' => ['barcode', 'sku', 'nama', 'deskripsi', 'kategori', 'harga_beli', 'harga_jual', 'stok', 'min_stok', 'max_stok', 'tipe_pajak', 'tarif_pajak', 'jenis_produk'],
            'customers' => ['nama', 'telepon', 'alamat'],
            default => abort(404),
        };

        return Excel::download(
            new class($headings) implements FromArray, WithHeadings
            {
                public function __construct(private array $headings) {}

                public function headings(): array
                {
                    return $this->headings;
                }

                public function array(): array
                {
                    return [];
                }
            },
            "template-{$type}.xlsx"
        );
    }
}
