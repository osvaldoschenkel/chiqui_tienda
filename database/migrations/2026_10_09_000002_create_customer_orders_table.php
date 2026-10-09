<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('number', 80)->unique();
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->json('items');
            $table->unsignedBigInteger('total_cents');
            $table->enum('status', ['pending', 'preparing', 'shipped', 'delivered', 'cancelled'])->default('pending');
            $table->enum('payment_status', ['pending', 'confirmed'])->default('pending');
            $table->string('delivery_method', 30)->default('pickup');
            $table->json('address')->nullable();
            $table->text('notes')->nullable();
            $table->string('tracking_code', 150)->nullable();
            $table->string('tracking_url', 2000)->nullable();
            $table->timestamp('payment_confirmed_at')->nullable();
            $table->foreignId('payment_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('stock_released_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('customer_orders'); }
};
