<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            // Mix & match bundle: any combination of the rule's bundle products totalling this qty forms one paket.
            $table->unsignedInteger('mix_match_total_qty')->nullable()->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropColumn('mix_match_total_qty');
        });
    }
};
