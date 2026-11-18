<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Product;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Row;

class ProductsImport implements OnEachRow, SkipsEmptyRows, WithChunkReading, WithHeadingRow, WithValidation
{
    private int $rowCount = 0;

    public function onRow(Row $sheetRow): void
    {
        $row = $sheetRow->toArray();
        $categoryName = trim($row['kategori'] ?? 'Umum');
        $category = Category::firstOrCreate(
            ['name' => $categoryName],
            ['description' => '', 'image' => 'default.png']
        );

        $barcode = (string) ($row['barcode'] ?? '');

        $type = strtolower(trim((string) ($row['jenis_produk'] ?? '')));
        $product = Product::firstOrNew(['barcode' => $barcode]);
        if (! $product->exists) {
            $product->stock = 0;
        }
        $product->fill(
            [
                'sku' => $row['sku'] ?? $barcode,
                'title' => $row['nama'] ?? '',
                'description' => $row['deskripsi'] ?? '',
                'category_id' => $category->id,
                'buy_price' => (int) ($row['harga_beli'] ?? 0),
                'sell_price' => (int) ($row['harga_jual'] ?? 0),
                'min_stock' => (int) ($row['min_stok'] ?? 0),
                'max_stock' => (int) ($row['max_stok'] ?? 0),
                'tax_type' => $row['tipe_pajak'] ?? 'exclusive',
                'tax_rate' => (float) ($row['tarif_pajak'] ?? 11.00),
            ]
        );
        if ($type !== '') {
            $product->is_sellable = $type === 'kasir';
        }
        $product->save();
        $this->rowCount++;
    }

    public function rules(): array
    {
        return [
            'jenis_produk' => ['nullable', function ($attribute, $value, $fail) {
                if (! in_array(strtolower(trim((string) $value)), ['', 'kasir', 'gudang saja'], true)) {
                    $fail('Jenis Produk harus Kasir atau Gudang saja.');
                }
            }],
            'barcode' => ['required'],
            'nama' => ['required', 'string', 'max:255'],
            'harga_beli' => ['nullable', 'numeric', 'min:0'],
            'harga_jual' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            'barcode.required' => 'Barcode wajib diisi.',
            'barcode.unique' => 'Barcode sudah terdaftar.',
            'nama.required' => 'Nama produk wajib diisi.',
        ];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }
}
