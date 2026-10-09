<?php

use App\Http\Controllers\ShopController;
use App\Http\Controllers\ShopOrderAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/tienda', [ShopController::class, 'index'])->name('shop.index');
    Route::get('/tienda/carrito', [ShopController::class, 'cart'])->name('shop.cart.index');
    Route::post('/tienda/carrito/{product}', [ShopController::class, 'add'])->whereNumber('product')->name('shop.cart.add');
    Route::patch('/tienda/carrito/{product}', [ShopController::class, 'update'])->whereNumber('product')->name('shop.cart.update');
    Route::delete('/tienda/carrito/{product}', [ShopController::class, 'remove'])->whereNumber('product')->name('shop.cart.remove');
    Route::post('/tienda/pedidos', [ShopController::class, 'store'])->name('shop.orders.store');
    Route::get('/mis-compras', [ShopController::class, 'orders'])->name('shop.orders.index');
    Route::get('/mis-compras/{order}', [ShopController::class, 'show'])->whereNumber('order')->name('shop.orders.show');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/pedidos-clientes', [ShopOrderAdminController::class, 'index'])->name('shop.admin.orders.index');
    Route::patch('/pedidos-clientes/{order}', [ShopOrderAdminController::class, 'update'])->whereNumber('order')->name('shop.admin.orders.update');
});
