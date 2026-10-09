<?php

namespace Tests\Feature;

use App\Models\CustomerOrder;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopFlowTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User { return User::factory()->create(['role' => 'customer']); }
    private function admin(): User { return User::factory()->create(['role' => 'admin']); }
    private function product(array $attributes = []): Product
    {
        return Product::create(array_replace([
            'name' => 'Auriculares Chiqui', 'sku' => (string) Str::uuid(), 'category' => 'Tecnología',
            'price_cents' => 125050, 'cost_cents' => 70000, 'stock' => 10, 'min_stock' => 2, 'active' => true,
        ], $attributes));
    }
    private function payload(array $attributes = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'delivery_method' => 'pickup', 'notes' => 'Coordinar retiro por la tarde.'], $attributes);
    }
    private function createOrder(User $user, Product $product, int $quantity = 2): CustomerOrder
    {
        $this->actingAs($user)->withSession(['shop.cart' => [$product->id => $quantity]])
            ->post(route('shop.orders.store'), $this->payload())->assertRedirect();

        return CustomerOrder::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function test_shop_requires_authentication_and_customers_cannot_administer_orders(): void
    {
        $this->get(route('shop.index'))->assertRedirect(route('login'));
        $this->get(route('shop.cart.index'))->assertRedirect(route('login'));
        $this->post(route('shop.orders.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('shop.orders.index'))->assertRedirect(route('login'));
        $this->get(route('shop.admin.orders.index'))->assertRedirect(route('login'));
        $this->actingAs($this->customer())->get(route('shop.admin.orders.index'))->assertForbidden();
        $this->patch(route('shop.admin.orders.update', 99999), ['status' => 'cancelled'])->assertForbidden();
        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_cart_merges_quantities_and_changes_no_inventory_before_checkout(): void
    {
        $product = $this->product();
        $this->actingAs($this->customer());
        $this->post(route('shop.cart.add', $product), ['quantity' => 2])->assertRedirect()->assertSessionHas('shop.cart.'.$product->id, 2);
        $this->post(route('shop.cart.add', $product), ['quantity' => 1])->assertSessionHas('shop.cart.'.$product->id, 3);
        $this->patch(route('shop.cart.update', $product), ['quantity' => 4])->assertRedirect(route('shop.cart.index'))->assertSessionHas('shop.cart.'.$product->id, 4);
        $this->assertSame(10, $product->fresh()->stock);
        $this->post(route('shop.cart.add', $product), ['quantity' => 101])->assertSessionHasErrors('quantity');
        $this->patch(route('shop.cart.update', $product), ['quantity' => 11])->assertSessionHasErrors('cart');
        $this->delete(route('shop.cart.remove', $product))->assertSessionHas('shop.cart', []);
        $product->update(['active' => false]);
        $this->post(route('shop.cart.add', $product), ['quantity' => 1])->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_checkout_uses_locked_server_prices_preserves_snapshots_and_never_creates_a_pos_sale(): void
    {
        $user = $this->customer(); $product = $this->product(['image_path' => 'products/example.png']);
        $this->actingAs($user)->post(route('shop.cart.add', $product), ['quantity' => 2]);
        $product->update(['price_cents' => 99001]);
        $data = $this->payload([
            'total_cents' => 1, 'status' => 'delivered', 'payment_status' => 'confirmed',
            'items' => [['product_id' => $product->id, 'quantity' => 500, 'unit_price_cents' => 1]],
            'address' => ['phone' => '1155550000', 'city' => 'CABA'],
        ]);
        $this->post(route('shop.orders.store'), $data)->assertRedirect();
        $order = CustomerOrder::sole();
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame(198002, $order->total_cents);
        $this->assertSame('pending', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pickup', $order->delivery_method);
        $this->assertSame(99001, $order->items[0]['unit_price_cents']);
        $this->assertSame(2, $order->items[0]['quantity']);
        $this->assertSame('products/example.png', $order->items[0]['image_path']);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertNull($order->payment_confirmed_at);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'delta' => -2, 'reference_type' => 'customer_order', 'reference_id' => $order->id]);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $product->update(['name' => 'Nombre nuevo', 'price_cents' => 4000]);
        $this->assertSame('Auriculares Chiqui', $order->fresh()->items[0]['name']);
        $this->assertSame(198002, $order->fresh()->total_cents);
    }

    public function test_checkout_retry_is_idempotent_after_cart_clear_and_keys_are_scoped_to_the_user(): void
    {
        $user = $this->customer(); $other = $this->customer(); $product = $this->product(); $data = $this->payload();
        $this->actingAs($user)->withSession(['shop.cart' => [$product->id => 2]])->post(route('shop.orders.store'), $data)->assertRedirect();
        $first = CustomerOrder::sole();
        $this->post(route('shop.orders.store'), $data)->assertRedirect(route('shop.orders.show', $first));
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(8, $product->fresh()->stock);
        $this->withSession(['shop.cart' => [$product->id => 3]])->post(route('shop.orders.store'), $data)->assertSessionHasErrors('request_id');
        $this->assertSame(8, $product->fresh()->stock);
        $this->actingAs($other)->withSession(['shop.cart' => [$product->id => 2]])->post(route('shop.orders.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('customer_orders', 2);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame(6, $product->fresh()->stock);
        $this->assertSame(1, CustomerOrder::where('user_id', $other->id)->where('request_id', $data['request_id'])->count());
    }

    public function test_insufficient_stock_rolls_back_the_whole_order_and_every_reservation(): void
    {
        $first = $this->product(['stock' => 8]); $second = $this->product(['stock' => 1]);
        $this->actingAs($this->customer())->withSession(['shop.cart' => [$first->id => 3, $second->id => 2]])
            ->post(route('shop.orders.store'), $this->payload())->assertSessionHasErrors('cart');
        $this->assertSame(8, $first->fresh()->stock);
        $this->assertSame(1, $second->fresh()->stock);
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_checkout_rejects_inactive_invalid_duplicate_and_excessive_cart_lines(): void
    {
        $product = $this->product(['active' => false]);
        $this->actingAs($this->customer())->withSession(['shop.cart' => [$product->id => 1]])
            ->post(route('shop.orders.store'), $this->payload())->assertSessionHasErrors('cart');
        $product->update(['active' => true]);
        $this->withSession(['shop.cart' => [$product->id => 101]])->post(route('shop.orders.store'), $this->payload())->assertSessionHasErrors('cart');
        $this->withSession(['shop.cart' => [$product->id => 1, '0'.$product->id => 1]])->post(route('shop.orders.store'), $this->payload())->assertSessionHasErrors('cart');
        $this->withSession(['shop.cart' => [$product->id => 1]])->post(route('shop.orders.store'), $this->payload(['delivery_method' => 'address']))->assertSessionHasErrors('delivery_method');
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_cart_can_remove_a_product_deleted_before_checkout(): void
    {
        $product = $this->product(); $id = $product->id;
        $this->actingAs($this->customer())->withSession(['shop.cart' => [$id => 1]]);
        $product->delete();
        $this->delete(route('shop.cart.remove', $id))->assertRedirect(route('shop.cart.index'))->assertSessionHas('shop.cart', []);
    }

    public function test_purchase_history_is_scoped_and_other_customers_orders_return_404(): void
    {
        $first = $this->customer(); $second = $this->customer(); $product = $this->product();
        $own = $this->createOrder($first, $product, 1); $other = $this->createOrder($second, $product, 1);
        $this->actingAs($first)->get(route('shop.orders.index'))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 1 && $orders->first()->id === $own->id);
        $this->get(route('shop.orders.show', $own))->assertOk()->assertViewHas('order', fn ($order) => $order->id === $own->id);
        $this->get(route('shop.orders.show', $other))->assertNotFound();
    }

    public function test_admin_cancels_unpaid_order_and_restores_archived_product_stock_exactly_once(): void
    {
        $product = $this->product(); $order = $this->createOrder($this->customer(), $product);
        $product->update(['active' => false]); $admin = $this->admin();
        $this->actingAs($admin)->patch(route('shop.admin.orders.update', $order), ['status' => 'cancelled', 'payment_status' => 'pending'])->assertRedirect(route('shop.admin.orders.index'));
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->stock_released_at);
        $this->assertSame($admin->id, $order->fresh()->cancelled_by);
        $this->patch(route('shop.admin.orders.update', $order), ['status' => 'cancelled', 'payment_status' => 'pending'])->assertRedirect();
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, StockMovement::where('reference_type', 'customer_order_cancel')->where('reference_id', $order->id)->count());
        $this->patchJson(route('shop.admin.orders.update', $order), ['status' => 'pending', 'payment_status' => 'pending'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_fulfilment_requires_explicit_manual_payment_and_advances_in_order(): void
    {
        $order = $this->createOrder($this->customer(), $this->product()); $admin = $this->admin();
        $this->actingAs($admin)->patchJson(route('shop.admin.orders.update', $order), ['status' => 'preparing', 'payment_status' => 'pending'])->assertUnprocessable()->assertJsonValidationErrors('payment_status');
        $this->patchJson(route('shop.admin.orders.update', $order), ['status' => 'shipped', 'payment_status' => 'confirmed'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->payment_confirmed_at);
        $this->patch(route('shop.admin.orders.update', $order), ['status' => 'preparing', 'payment_status' => 'confirmed'])->assertRedirect();
        $this->assertSame('confirmed', $order->fresh()->payment_status);
        $this->assertSame($admin->id, $order->fresh()->payment_confirmed_by);
        $this->assertNotNull($order->fresh()->payment_confirmed_at);
        $this->patch(route('shop.admin.orders.update', $order), ['status' => 'shipped', 'payment_status' => 'confirmed'])->assertRedirect();
        $this->patch(route('shop.admin.orders.update', $order), ['status' => 'delivered', 'payment_status' => 'confirmed'])->assertRedirect();
        $this->assertSame('delivered', $order->fresh()->status);
        $this->patchJson(route('shop.admin.orders.update', $order), ['status' => 'delivered', 'payment_status' => 'pending'])->assertUnprocessable()->assertJsonValidationErrors('payment_status');
        $this->patchJson(route('shop.admin.orders.update', $order), ['status' => 'preparing', 'payment_status' => 'confirmed'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_confirmed_payment_cannot_be_cancelled_or_reverted_and_clients_cannot_confirm_it(): void
    {
        $customer = $this->customer(); $product = $this->product(); $order = $this->createOrder($customer, $product);
        $this->patchJson(route('shop.admin.orders.update', $order), ['status' => 'pending', 'payment_status' => 'confirmed'])->assertForbidden();
        $this->actingAs($this->admin())->patch(route('shop.admin.orders.update', $order), ['status' => 'pending', 'payment_status' => 'confirmed'])->assertRedirect();
        $this->patchJson(route('shop.admin.orders.update', $order), ['status' => 'cancelled', 'payment_status' => 'confirmed'])->assertUnprocessable()->assertJsonValidationErrors('payment_status');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->payment_status);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertNull($order->fresh()->stock_released_at);
        $this->assertSame(0, StockMovement::where('reference_type', 'customer_order_cancel')->count());
    }

    public function test_admin_tracking_links_require_https_and_store_without_automatic_shipping(): void
    {
        $order = $this->createOrder($this->customer(), $this->product());
        $this->actingAs($this->admin())->patchJson(route('shop.admin.orders.update', $order), ['status' => 'pending', 'tracking_url' => 'http://tracking.example.test/order'])->assertUnprocessable()->assertJsonValidationErrors('tracking_url');
        $this->patch(route('shop.admin.orders.update', $order), ['status' => 'pending', 'tracking_code' => 'COORD-123', 'tracking_url' => 'https://tracking.example.test/order'])->assertRedirect();
        $this->assertSame('https://tracking.example.test/order', $order->fresh()->tracking_url);
        $this->assertSame('COORD-123', $order->fresh()->tracking_code);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }
}
