<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->boolean('cash_enabled')->default(true)->after('default_gateway');
            $table->boolean('qris_static_enabled')->default(false)->after('bank_transfer_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn(['cash_enabled', 'qris_static_enabled']);
        });
    }
};
