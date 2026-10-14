<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('products', function (Blueprint $t) {
            $t->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('weight_grams')->default(0);
            $t->decimal('height_cm', 8, 2)->default(0);
            $t->decimal('width_cm', 8, 2)->default(0);
            $t->decimal('length_cm', 8, 2)->default(0);
            $t->decimal('vat_rate', 5, 2)->default(21);
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->unsignedBigInteger('shipping_cents')->default(0);
        });
        Schema::create('integration_settings', function (Blueprint $t) {
            $t->id();
            $t->text('values');
            $t->timestamps();
        });
        Schema::table('customer_orders', function (Blueprint $t) {
            $t->string('payment_method')->default('manual');
            $t->string('payment_id')->nullable()->unique();
            $t->text('payment_url')->nullable();
            $t->string('preference_id')->nullable();
            $t->unsignedBigInteger('shipping_cents')->default(0);
            $t->json('shipping_selection')->nullable();
            $t->string('shipment_id')->nullable()->unique();
            $t->string('shipment_state')->nullable();
            $t->json('billing')->nullable();
            $t->timestamp('reservation_expires_at')->nullable()->index();
            $t->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $t->text('payment_review')->nullable();
        });
        Schema::create('shipping_quotes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('cart_hash', 64);
            $t->json('destination');
            $t->json('options');
            $t->timestamp('expires_at')->index();
            $t->timestamps();
        });
        Schema::create('fiscal_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_order_id')->unique()->constrained()->restrictOnDelete();
            $t->string('issuer_cuit', 11);
            $t->string('environment');
            $t->unsignedInteger('point_of_sale');
            $t->unsignedInteger('voucher_type');
            $t->unsignedBigInteger('voucher_number');
            $t->string('status')->default('pending');
            $t->json('request');
            $t->json('response')->nullable();
            $t->string('cae')->nullable();
            $t->date('cae_expires_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->unique(['environment', 'issuer_cuit', 'point_of_sale', 'voucher_type', 'voucher_number'], 'fiscal_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_invoices');
        Schema::dropIfExists('shipping_quotes');
        Schema::table('customer_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sale_id');
            $t->dropColumn(['payment_method', 'payment_id', 'payment_url', 'preference_id', 'shipping_cents', 'shipping_selection', 'shipment_id', 'shipment_state', 'billing', 'reservation_expires_at', 'payment_review']);
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->dropColumn('shipping_cents');
        });
        Schema::dropIfExists('integration_settings');
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('brand_id');
            $t->dropColumn(['weight_grams', 'height_cm', 'width_cm', 'length_cm', 'vat_rate']);
        });
        Schema::dropIfExists('brands');
    }
};
