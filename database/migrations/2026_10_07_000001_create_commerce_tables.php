<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('document', 50)->nullable()->unique();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('credit_limit_cents')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('document', 50)->nullable()->unique();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku', 100)->unique();
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('category', 100)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('cost_cents')->default(0);
            $table->unsignedBigInteger('price_cents');
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('min_stock')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 80)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('payment_method', ['cash', 'card', 'transfer', 'account']);
            $table->unsignedBigInteger('total_cents');
            $table->enum('status', ['completed', 'cancelled'])->default('completed');
            $table->uuid('request_id')->unique();
            $table->string('request_hash', 64);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku', 100);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('subtotal_cents');
            $table->timestamps();
        });
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 80)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->enum('payment_method', ['cash', 'transfer', 'account']);
            $table->unsignedBigInteger('total_cents');
            $table->enum('status', ['completed', 'cancelled'])->default('completed');
            $table->uuid('request_id')->unique();
            $table->string('request_hash', 64);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku', 100);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost_cents');
            $table->unsignedBigInteger('subtotal_cents');
            $table->timestamps();
        });
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('direction', ['debit', 'credit']);
            $table->unsignedBigInteger('amount_cents');
            $table->uuid('request_id')->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description', 500);
            $table->enum('payment_method', ['cash', 'card', 'transfer', 'account'])->nullable();
            $table->timestamp('date');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
            $table->index(['customer_id', 'date']);
            $table->index(['supplier_id', 'date']);
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('delta');
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'ledger_entries', 'purchase_items', 'purchases', 'sale_items', 'sales', 'products', 'suppliers', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
