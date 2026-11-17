<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaticQrisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access', 'transactions-confirm-payment'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    public function test_static_qris_checkout_is_pending_until_cashier_confirms(): void
    {
        [$cashier, $product] = $this->prepare();

        $response = $this->actingAs($cashier)->post(route('transactions.store'), [
            'customer_id' => null,
            'payment_gateway' => 'qris_static',
        ]);

        $transaction = Transaction::latest('id')->firstOrFail();
        $response->assertRedirect(route('transactions.history'));
        $this->assertNull($transaction->customer_id);
        $this->assertSame('qris_static', $transaction->payment_method);
        $this->assertSame('pending', $transaction->payment_status);
        $this->assertSame(24, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('transaction_tenders', [
            'transaction_id' => $transaction->id,
            'method' => 'qris_static',
            'payment_status' => 'pending',
        ]);

        $this->confirm($cashier, $transaction)->assertSessionHas('success');

        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $this->assertDatabaseHas('transaction_tenders', [
            'transaction_id' => $transaction->id,
            'method' => 'qris_static',
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'transaction.payment_confirmed',
            'auditable_id' => $transaction->id,
        ]);
    }

    public function test_second_confirmation_does_not_process_payment_again(): void
    {
        [$cashier] = $this->prepare();

        $this->actingAs($cashier)->post(route('transactions.store'), [
            'payment_gateway' => 'qris_static',
        ]);
        $transaction = Transaction::latest('id')->firstOrFail();

        $this->confirm($cashier, $transaction)->assertSessionHas('success');
        $this->confirm($cashier, $transaction)->assertSessionHas('error', 'Transaksi sudah dibayar.');

        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'transaction.payment_confirmed')
            ->where('auditable_id', $transaction->id)
            ->count());
    }

    public function test_cash_checkout_is_rejected_when_cash_disabled(): void
    {
        [$cashier, $product] = $this->prepare();

        $this->actingAs($cashier)->post(route('transactions.store'), [
            'cash' => 10000,
            'grand_total' => 10000,
        ])->assertSessionHasErrors('payment_gateway');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(25, (int) $product->fresh()->stock);
    }

    public function test_split_with_cash_is_rejected_when_cash_disabled(): void
    {
        [$cashier] = $this->prepare();

        $this->actingAs($cashier)->post(route('transactions.store'), [
            'tenders' => [
                ['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000],
                ['method' => 'qris', 'amount' => 5000],
            ],
        ])->assertSessionHasErrors('payment_gateway');

        $this->assertSame(0, Transaction::count());
    }

    public function test_physical_qris_close_order_does_not_require_qr_image(): void
    {
        [$cashier] = $this->prepare();

        $this->actingAs($cashier)->post(route('transactions.store'), [
            'payment_gateway' => 'qris_static',
        ])->assertRedirect(route('transactions.history'));

        $this->assertSame('pending', Transaction::latest('id')->value('payment_status'));
    }

    public function test_history_lists_pending_physical_qris_order(): void
    {
        [$cashier] = $this->prepare();

        $this->actingAs($cashier)->post(route('transactions.store'), [
            'payment_gateway' => 'qris_static',
        ]);
        $transaction = Transaction::latest('id')->firstOrFail();

        $this->actingAs($cashier)->get(route('transactions.history'))
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Transactions/History')
                ->where('transactions.data.0.invoice', $transaction->invoice)
                ->where('transactions.data.0.payment_method', 'qris_static')
                ->where('transactions.data.0.payment_status', 'pending'));
    }

    public function test_static_qris_is_rejected_when_disabled(): void
    {
        [$cashier] = $this->prepare(qrisEnabled: false);

        $this->actingAs($cashier)->post(route('transactions.store'), [
            'payment_gateway' => 'qris_static',
        ])->assertSessionHas('error', 'Gateway pembayaran belum dikonfigurasi.');

        $this->assertSame(0, Transaction::count());
    }

    public function test_default_payment_setting_uses_physical_qris(): void
    {
        $setting = PaymentSetting::forOutletOrCreate(null);

        $this->assertSame('qris_static', $setting->default_gateway);
        $this->assertTrue($setting->isGatewayReady(PaymentSetting::GATEWAY_QRIS_STATIC));
    }

    private function prepare(bool $qrisEnabled = true): array
    {
        $cashier = User::factory()->create();
        $cashier->markEmailAsVerified();
        $cashier->givePermissionTo(['transactions-access', 'cashier-shifts-access', 'transactions-confirm-payment']);

        CashierShift::create([
            'user_id' => $cashier->id,
            'opened_by' => $cashier->id,
            'opened_at' => now(),
            'opening_cash' => 0,
            'expected_cash' => 0,
            'status' => 'open',
        ]);

        $category = Category::create([
            'name' => 'Booth '.uniqid(),
            'description' => 'Kategori pengujian',
            'image' => 'category.png',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'title' => 'Produk Booth',
            'description' => 'Deskripsi produk uji.',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 25,
            'tax_rate' => 0,
        ]);

        PaymentSetting::create([
            'default_gateway' => 'qris_static',
            'cash_enabled' => false,
            'qris_static_enabled' => $qrisEnabled,
        ]);

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $product->sell_price,
        ]);

        return [$cashier, $product];
    }

    private function confirm(User $cashier, Transaction $transaction)
    {
        return $this->actingAs($cashier)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('transactions.print', $transaction->invoice))
            ->patch(route('transactions.confirm-payment', $transaction));
    }
}
