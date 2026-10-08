<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create();
        $this->actingAs($this->operator);
    }

    private function productPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Galletitas Chiqui', 'sku' => 'CHI-001', 'barcode' => '779000001',
            'category' => 'Almacén', 'description' => 'Paquete de galletitas',
            'cost' => '800,25', 'price' => '1250.50', 'stock' => 10, 'min_stock' => 2, 'active' => 1,
        ], $overrides);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_replace([
            'name' => 'Galletitas Chiqui', 'sku' => (string) Str::uuid(), 'cost_cents' => 80025,
            'price_cents' => 125050, 'stock' => 10, 'min_stock' => 2, 'active' => true,
        ], $overrides));
    }

    private function png(string $name): UploadedFile
    {
        // Real bytes exercise MIME detection and avoid requiring GD in the test runtime.
        return $this->upload($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lX8AAAAASUVORK5CYII='));
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'chiqui-upload-');
        file_put_contents($path, $contents);
        $this->beforeApplicationDestroyed(function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        // Laravel's fake File intentionally guesses MIME from its name; use a real UploadedFile instead.
        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    public function test_customer_crud_normalises_fields_and_stores_credit_limit_exactly(): void
    {
        $this->get(route('customers.create'))->assertOk();
        $this->post(route('customers.store'), [
            'name' => 'Ana Chiqui', 'document' => ' 30123456 ', 'email' => ' ANA@EXAMPLE.COM ',
            'phone' => '1155550000', 'credit_limit' => '10000,55', 'active' => 1,
        ])->assertRedirect();
        $customer = Customer::sole();
        $this->assertSame('30123456', $customer->document);
        $this->assertSame('ana@example.com', $customer->email);
        $this->assertSame(1000055, $customer->credit_limit_cents);

        $this->get(route('customers.index', ['q' => '30123456']))->assertOk()->assertSee('Ana Chiqui');
        $this->get(route('customers.show', $customer))->assertOk()->assertSee('Ana Chiqui');
        $this->get(route('customers.edit', $customer))->assertOk();
        $this->put(route('customers.update', $customer), [
            'name' => 'Ana Actualizada', 'document' => '30123456', 'credit_limit' => '20000.01',
        ])->assertRedirect(route('customers.show', $customer));
        $this->assertSame('Ana Actualizada', $customer->fresh()->name);
        $this->assertSame(2000001, $customer->fresh()->credit_limit_cents);
        $this->assertFalse($customer->fresh()->active);

        $this->delete(route('customers.destroy', $customer))->assertRedirect(route('customers.index'));
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_supplier_crud_allows_same_document_on_update_and_deletes_unused_contact(): void
    {
        $this->get(route('suppliers.create'))->assertOk();
        $this->post(route('suppliers.store'), [
            'name' => 'Distribuidora Sur', 'document' => '30700111222', 'email' => ' PEDIDOS@SUR.COM ',
            'address' => 'Buenos Aires', 'active' => 1,
        ])->assertRedirect();
        $supplier = Supplier::sole();
        $this->assertSame('pedidos@sur.com', $supplier->email);
        $this->get(route('suppliers.index', ['q' => '30700111222']))->assertOk()->assertSee('Distribuidora Sur');
        $this->get(route('suppliers.show', $supplier))->assertOk();
        $this->get(route('suppliers.edit', $supplier))->assertOk();
        $this->put(route('suppliers.update', $supplier), [
            'name' => 'Distribuidora Norte', 'document' => '30700111222', 'active' => 1,
        ])->assertRedirect(route('suppliers.show', $supplier));
        $this->assertSame('Distribuidora Norte', $supplier->fresh()->name);
        $this->delete(route('suppliers.destroy', $supplier))->assertRedirect(route('suppliers.index'));
        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_contacts_reject_duplicate_documents_negative_limits_and_invalid_email(): void
    {
        Customer::create(['name' => 'Original', 'document' => '123']);
        Supplier::create(['name' => 'Original', 'document' => '123']);

        $this->post(route('customers.store'), ['name' => 'Duplicado', 'document' => '123', 'email' => 'bad-email', 'credit_limit' => '-1'])
            ->assertSessionHasErrors(['document', 'email', 'credit_limit']);
        $this->post(route('suppliers.store'), ['name' => 'Duplicado', 'document' => '123'])
            ->assertSessionHasErrors('document');
        $this->post(route('customers.store'), ['name' => 'Precisión', 'credit_limit' => '0.001'])
            ->assertSessionHasErrors('credit_limit');
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_product_create_and_edit_audits_stock_and_money_without_double_movement(): void
    {
        $supplier = Supplier::create(['name' => 'Distribuidora']);
        $this->post(route('products.store'), $this->productPayload(['supplier_id' => $supplier->id]))
            ->assertRedirect(route('products.index'));
        $product = Product::sole();
        $this->assertSame(80025, $product->cost_cents);
        $this->assertSame(125050, $product->price_cents);
        $this->assertSame(10, $product->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id, 'delta' => 10, 'reference_type' => 'initial', 'user_id' => $this->operator->id,
        ]);
        $this->get(route('products.edit', $product))->assertOk()->assertSee('CHI-001');
        $this->put(route('products.update', $product), $this->productPayload([
            'name' => 'Galletitas grandes', 'stock' => 7, 'expected_stock' => 10, 'price' => '1400,99', 'supplier_id' => $supplier->id,
        ]))->assertRedirect(route('products.index'));
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(140099, $product->fresh()->price_cents);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'delta' => -3, 'reference_type' => 'adjustment']);
        $this->put(route('products.update', $product), $this->productPayload(['stock' => 7, 'expected_stock' => 7]))
            ->assertRedirect(route('products.index'));
        $this->assertSame(2, StockMovement::where('product_id', $product->id)->count());
        $this->delete(route('products.destroy', $product))->assertSessionHasErrors('product');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_product_image_replacement_and_unused_product_deletion_clean_up_files(): void
    {
        Storage::fake('public');
        $this->post(route('products.store'), $this->productPayload(['stock' => 0, 'image' => $this->png('first.png')]))
            ->assertRedirect(route('products.index'));
        $product = Product::sole();
        $firstPath = $product->image_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->put(route('products.update', $product), $this->productPayload(['stock' => 0, 'expected_stock' => 0, 'image' => $this->png('second.png')]))
            ->assertRedirect(route('products.index'));
        $secondPath = $product->fresh()->image_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->delete(route('products.destroy', $product))->assertRedirect(route('products.index'));
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        Storage::disk('public')->assertMissing($secondPath);
    }

    public function test_invalid_product_data_and_code_disguised_as_image_are_rejected_without_files(): void
    {
        Storage::fake('public');
        $this->post(route('products.store'), $this->productPayload([
            'cost' => '-1', 'price' => '1.001', 'stock' => -1, 'min_stock' => -1,
            'supplier_id' => 999, 'image' => $this->upload('photo.png', '<?php echo 1;'),
        ]))->assertSessionHasErrors(['cost', 'price', 'stock', 'min_stock', 'supplier_id', 'image']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $existing = $this->product(['sku' => 'CHI-001', 'barcode' => '779000001']);
        $this->post(route('products.store'), $this->productPayload())->assertSessionHasErrors(['sku', 'barcode']);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['id' => $existing->id]);
    }

    public function test_stale_product_form_cannot_restore_stock_after_an_intervening_sale(): void
    {
        Storage::fake('public');
        $product = $this->product(['sku' => 'CHI-001']);
        $this->get(route('products.edit', $product))->assertOk()->assertSee('expected_stock', false);
        $this->post(route('sales.store'), [
            'request_id' => (string) Str::uuid(), 'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->put(route('products.update', $product), $this->productPayload([
            'expected_stock' => 10, 'name' => 'Edición desactualizada', 'price' => '2000', 'image' => $this->png('stale.png'),
        ]))->assertSessionHasErrors('stock');

        $this->assertSame(9, $product->fresh()->stock);
        $this->assertSame('Galletitas Chiqui', $product->fresh()->name);
        $this->assertSame(125050, $product->fresh()->price_cents);
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_checkout_uses_server_prices_and_receipt_preserves_snapshot_after_product_edit(): void
    {
        $product = $this->product();
        $customer = Customer::create(['name' => 'Cliente de cuenta', 'active' => true]);
        $data = [
            'request_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'payment_method' => 'account',
            'total_cents' => 1, 'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price_cents' => 1]],
        ];
        $this->post(route('sales.store'), $data)->assertRedirect();
        $sale = Sale::sole();
        $this->assertSame(250100, $sale->total_cents);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(250100, $customer->balance_cents);
        $this->post(route('sales.store'), $data)->assertRedirect(route('sales.show', $sale));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('ledger_entries', 1);

        $product->update(['name' => 'Nuevo nombre', 'price_cents' => 999999]);
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Galletitas Chiqui')->assertSee('S-000001');
        $this->assertSame(125050, $sale->items()->sole()->unit_price_cents);
        $this->delete(route('customers.destroy', $customer))->assertSessionHasErrors('contact');
        $this->delete(route('products.destroy', $product))->assertSessionHasErrors('product');

        $this->post(route('sales.cancel', $sale))->assertRedirect(route('sales.show', $sale));
        $this->post(route('sales.cancel', $sale))->assertRedirect(route('sales.show', $sale));
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, $customer->balance_cents);
        $this->assertSame('cancelled', $sale->fresh()->status);
    }

    public function test_cash_sale_still_protects_customer_history_and_invalid_checkout_is_atomic(): void
    {
        $product = $this->product(['stock' => 1]);
        $customer = Customer::create(['name' => 'Cliente contado', 'active' => true]);
        $data = [
            'request_id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];
        $this->post(route('sales.store'), $data)->assertSessionHasErrors('items');
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertDatabaseCount('sales', 0);
        $data['items'][0]['quantity'] = 1;
        $this->post(route('sales.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->delete(route('customers.destroy', $customer))->assertSessionHasErrors('contact');
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_purchase_receipt_stock_cost_and_supplier_account_are_recorded_through_http(): void
    {
        $product = $this->product(['stock' => 0]);
        $supplier = Supplier::create(['name' => 'Distribuidora Chiqui', 'active' => true]);
        $data = [
            'request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'payment_method' => 'account',
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => '900,75']],
        ];
        $this->post(route('purchases.store'), $data)->assertRedirect();
        $purchase = Purchase::sole();
        $this->assertSame(270225, $purchase->total_cents);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(90075, $product->fresh()->cost_cents);
        $this->assertSame(270225, $supplier->balance_cents);
        $this->get(route('purchases.show', $purchase))->assertOk()->assertSee('P-000001')->assertSee('Distribuidora Chiqui');
        $this->post(route('purchases.store'), $data)->assertRedirect(route('purchases.show', $purchase));
        $this->assertDatabaseCount('purchases', 1);
        $this->delete(route('suppliers.destroy', $supplier))->assertSessionHasErrors('contact');
        $this->post(route('purchases.cancel', $purchase))->assertRedirect(route('purchases.show', $purchase));
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $supplier->balance_cents);
        $this->assertSame('cancelled', $purchase->fresh()->status);
    }

    public function test_supplier_with_assigned_product_cannot_be_deleted(): void
    {
        $supplier = Supplier::create(['name' => 'Proveedor vinculado']);
        $this->product(['supplier_id' => $supplier->id]);
        $this->delete(route('suppliers.destroy', $supplier))->assertSessionHasErrors('contact');
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_account_movements_use_route_contact_and_accept_settlement_of_inactive_customer(): void
    {
        $customer = Customer::create(['name' => 'Ana cuenta', 'active' => true]);
        $supplier = Supplier::create(['name' => 'Proveedor cuenta', 'active' => true]);
        $this->post(route('customers.ledger.store', $customer), [
            'request_id' => (string) Str::uuid(), 'direction' => 'debit', 'amount' => '100,55', 'description' => 'Saldo inicial',
            'supplier_id' => $supplier->id, 'amount_cents' => 1,
        ])->assertRedirect(route('customers.show', $customer));
        $this->assertSame(10055, $customer->balance_cents);
        $this->assertSame(0, $supplier->balance_cents);
        $entry = LedgerEntry::sole();
        $this->assertNull($entry->supplier_id);
        $this->assertSame($this->operator->id, $entry->user_id);

        $customer->update(['active' => false]);
        $this->post(route('customers.ledger.store', $customer), [
            'request_id' => (string) Str::uuid(), 'direction' => 'credit', 'amount' => '50.25', 'description' => 'Pago efectivo', 'payment_method' => 'cash',
        ])->assertRedirect(route('customers.show', $customer));
        $this->assertSame(5030, $customer->balance_cents);
        $this->post(route('customers.ledger.store', $customer), [
            'request_id' => (string) Str::uuid(), 'direction' => 'debit', 'amount' => '1', 'description' => 'Nuevo cargo',
        ])->assertSessionHasErrors('customer_id');
        $this->delete(route('customers.destroy', $customer))->assertSessionHasErrors('contact');

        $this->post(route('suppliers.ledger.store', $supplier), [
            'request_id' => (string) Str::uuid(), 'direction' => 'debit', 'amount' => '300.10', 'description' => 'Saldo proveedor',
        ])->assertRedirect(route('suppliers.show', $supplier));
        $this->post(route('suppliers.ledger.store', $supplier), [
            'request_id' => (string) Str::uuid(), 'direction' => 'credit', 'amount' => '100.10', 'description' => 'Pago proveedor', 'payment_method' => 'transfer',
        ])->assertRedirect(route('suppliers.show', $supplier));
        $this->assertSame(20000, $supplier->balance_cents);
        $this->get(route('accounts.index'))->assertOk()->assertSee('Ana cuenta')->assertSee('Proveedor cuenta');
    }

    public function test_account_rejects_zero_negative_precision_and_future_dated_movements(): void
    {
        $customer = Customer::create(['name' => 'Cuenta validada', 'active' => true]);
        foreach (['0', '-1', '0.001'] as $amount) {
            $this->post(route('customers.ledger.store', $customer), ['request_id' => (string) Str::uuid(), 'direction' => 'credit', 'amount' => $amount, 'description' => 'Pago inválido'])
                ->assertSessionHasErrors('amount');
        }
        $this->post(route('customers.ledger.store', $customer), [
            'request_id' => (string) Str::uuid(), 'direction' => 'debit', 'amount' => '1', 'description' => 'Fecha futura', 'date' => now()->addDay()->format('Y-m-d'),
        ])->assertSessionHasErrors('date');
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, $customer->balance_cents);
    }

    public function test_repeated_account_payment_does_not_duplicate_balance_and_reused_token_cannot_change_owner(): void
    {
        $customer = Customer::create(['name' => 'Cliente con pago', 'active' => true]);
        $otherCustomer = Customer::create(['name' => 'Otro cliente', 'active' => true]);
        $data = [
            'request_id' => (string) Str::uuid(), 'direction' => 'credit', 'amount' => '20,50',
            'description' => 'Pago efectivo', 'payment_method' => 'cash',
        ];
        $this->post(route('customers.ledger.store', $customer), $data)->assertRedirect(route('customers.show', $customer));
        $this->post(route('customers.ledger.store', $customer), $data)->assertRedirect(route('customers.show', $customer));
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(-2050, $customer->balance_cents);

        $this->post(route('customers.ledger.store', $otherCustomer), $data)->assertSessionHasErrors('request_id');
        $this->assertSame(0, $otherCustomer->balance_cents);
        $data['amount'] = '25.00';
        $this->post(route('customers.ledger.store', $customer), $data)->assertSessionHasErrors('request_id');
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(-2050, $customer->balance_cents);
    }

    public function test_contact_and_account_movement_text_is_html_escaped(): void
    {
        $name = '<script>alert("name")</script>';
        $description = '<img src=x onerror=alert("ledger")>';
        $customer = Customer::create(['name' => $name, 'notes' => $description, 'active' => true]);
        $this->post(route('customers.ledger.store', $customer), [
            'request_id' => (string) Str::uuid(), 'direction' => 'debit', 'amount' => '1', 'description' => $description,
        ])->assertRedirect(route('customers.show', $customer));

        $this->get(route('customers.show', $customer))->assertOk()
            ->assertSee($name)->assertSee($description)->assertDontSee($name, false)->assertDontSee($description, false);
        $this->get(route('customers.index'))->assertOk()->assertSee($name)->assertDontSee($name, false);
        $this->get(route('accounts.index'))->assertOk()->assertSee($name)->assertDontSee($name, false);
    }

    public function test_main_mobile_management_pages_render_and_query_filters_do_not_leak_other_records(): void
    {
        Customer::create(['name' => 'Visible cliente', 'active' => true]);
        Customer::create(['name' => 'Oculto cliente', 'active' => false]);
        Supplier::create(['name' => 'Proveedor visible', 'active' => true]);
        $this->product(['name' => 'Artículo mínimo QA', 'stock' => 1, 'min_stock' => 2, 'category' => 'Bebidas']);
        $this->product(['name' => 'Artículo suficiente QA', 'stock' => 100, 'min_stock' => 2, 'category' => 'Almacén']);

        foreach (['dashboard', 'products.create', 'sales.index', 'sales.create', 'purchases.index', 'purchases.create', 'accounts.index'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('name="viewport"', false)
                ->assertDontSee('@include(', false)->assertDontSee('@endif', false);
        }
        $this->get(route('customers.index', ['status' => 'active']))->assertOk()->assertSee('Visible cliente')->assertDontSee('Oculto cliente');
        $this->get(route('products.index', ['low_stock' => 1]))->assertOk()->assertSee('Artículo mínimo QA')->assertDontSee('Artículo suficiente QA');
        $this->get(route('products.index', ['q' => 'Artículo suficiente QA']))->assertOk()->assertSee('Artículo suficiente QA')->assertDontSee('Artículo mínimo QA');
    }
}
