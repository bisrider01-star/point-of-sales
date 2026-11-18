<?php

namespace Tests\Feature\Products;

use App\Exports\ProductsExport;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->givePermissionTo(Permission::create(['name' => 'products-import']));
        $this->actingAs($user);
    }

    public function test_import_updates_by_barcode_and_preserves_type_and_stock_on_round_trip(): void
    {
        $this->upload("barcode,nama,jenis_produk,stok\nMILK,Susu,Gudang saja,50\nGEL,Gelato,Kasir,10\n")->assertSessionHas('success');
        $milk = Product::whereBarcode('MILK')->firstOrFail();
        $this->assertFalse($milk->is_sellable);
        $this->assertTrue(Product::whereBarcode('GEL')->firstOrFail()->is_sellable);
        $this->assertEquals(0, $milk->stock);
        $milk->update(['stock' => 7]);

        $this->upload("barcode,nama\nMILK,Susu Segar\n")->assertSessionHas('success');
        $file = UploadedFile::fake()->createWithContent('produk.xlsx', Excel::raw(new ProductsExport, \Maatwebsite\Excel\Excel::XLSX));
        $this->post(route('import.products'), ['file' => $file])->assertSessionHas('success');

        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['barcode' => 'MILK', 'title' => 'Susu Segar', 'stock' => 7, 'is_sellable' => false]);
    }

    public function test_invalid_type_reports_row_and_rolls_back(): void
    {
        $this->upload("barcode,nama,jenis_produk\nMILK,Susu,Gudang saja\nGEL,Gelato,Salah\n")
            ->assertSessionHasErrors(['file' => 'Baris 3: Jenis Produk harus Kasir atau Gudang saja.']);
        $this->assertDatabaseCount('products', 0);
    }

    private function upload(string $csv): TestResponse
    {
        return $this->post(route('import.products'), ['file' => UploadedFile::fake()->createWithContent('produk.csv', $csv)]);
    }
}
