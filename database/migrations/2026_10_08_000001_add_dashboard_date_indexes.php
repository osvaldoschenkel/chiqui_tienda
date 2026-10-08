<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'sales_status_created_at_index');
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'purchases_status_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropIndex('sales_status_created_at_index'));
        Schema::table('purchases', fn (Blueprint $table) => $table->dropIndex('purchases_status_created_at_index'));
    }
};
