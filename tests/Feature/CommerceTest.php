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
use App\Services\CommerceService;
use App\Services\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use RefreshDatabase;

    private CommerceService $commerce;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->commerce = app(CommerceService::class);
        $this->operator = User::create(['name' => 'Operador', 'email' => 'operator@example.test', 'password' => 'password-for-tests']);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge(['name' => 'Auriculares', 'sku' => (string) Str::uuid(), 'price_cents' => 125050, 'cost_cents' => 70000, 'stock' => 10, 'min_stock' => 2, 'active' => true], $attributes));
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::create(array_merge(['name' => 'Ana Torres', 'credit_limit_cents' => 0, 'active' => true], $attributes));
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['name' => 'Distribuidora Sur', 'active' => true]);
    }

    private function saleData(Product $product, int $quantity = 1, ?Customer $customer = null, string $method = 'cash'): array
    {
        return ['request_id' => (string) Str::uuid(), 'customer_id' => $customer?->id, 'payment_method' => $method, 'items' => [['product_id' => $product->id, 'quantity' => $quantity]]];
    }

    private function assertRejected(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('La operación debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_sales_use_server_prices_and_record_stock_and_receivable_atomically(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $data = $this->saleData($product, 3, $customer, 'account');
        $data['items'][0]['price'] = '0.01';
        $sale = $this->commerce->sell($data, $this->operator->id);

        $this->assertSame(375150, $sale->total_cents);
        $this->assertSame('S-000001', $sale->number);
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(375150, $customer->balance_cents);
        $this->assertSame(125050, $sale->items->first()->unit_price_cents);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'delta' => -3, 'reference_type' => 'sale', 'reference_id' => $sale->id]);
        $this->assertDatabaseHas('ledger_entries', ['customer_id' => $customer->id, 'direction' => 'debit', 'amount_cents' => 375150, 'reference_type' => 'sale']);
    }

    public function test_insufficient_stock_rolls_back_every_product_and_sale(): void
    {
        $first = $this->product(['stock' => 8]);
        $second = $this->product(['stock' => 1]);
        $data = $this->saleData($first, 2);
        $data['items'][] = ['product_id' => $second->id, 'quantity' => 2];
        $this->assertRejected(fn () => $this->commerce->sell($data, $this->operator->id), 'items');

        $this->assertSame(8, $first->fresh()->stock);
        $this->assertSame(1, $second->fresh()->stock);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_repeated_request_returns_same_sale_and_payload_mismatch_is_rejected(): void
    {
        $product = $this->product();
        $data = $this->saleData($product, 2);
        $first = $this->commerce->sell($data, $this->operator->id);
        $second = $this->commerce->sell($data, $this->operator->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('stock_movements', 1);

        $data['items'][0]['quantity'] = 3;
        $this->assertRejected(fn () => $this->commerce->sell($data, $this->operator->id), 'request_id');
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_credit_limit_applies_to_sales_and_manual_debits_but_payments_can_create_credit(): void
    {
        $product = $this->product(['price_cents' => 6000]);
        $customer = $this->customer(['credit_limit_cents' => 10000]);
        $first = $this->commerce->sell($this->saleData($product, 1, $customer, 'account'), $this->operator->id);
        $this->assertSame(6000, $customer->balance_cents);
        $this->assertRejected(fn () => $this->commerce->sell($this->saleData($product, 1, $customer, 'account'), $this->operator->id), 'customer_id');
        $this->assertRejected(fn () => $this->commerce->ledger(['customer_id' => $customer->id, 'direction' => 'debit', 'amount' => '40.01', 'description' => 'Cargo manual'], $this->operator->id), 'customer_id');
        $this->commerce->ledger(['customer_id' => $customer->id, 'direction' => 'credit', 'amount' => '100.50', 'description' => 'Pago a favor', 'payment_method' => 'cash'], $this->operator->id);
        $this->assertSame(-4050, $customer->balance_cents);
        $this->commerce->sell($this->saleData($product, 2, $customer, 'account'), $this->operator->id);
        $this->assertSame(7950, $customer->balance_cents);
        $this->assertSame('completed', $first->fresh()->status);
    }

    public function test_cancelling_account_sale_restores_archived_product_and_reverses_once(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $sale = $this->commerce->sell($this->saleData($product, 2, $customer, 'account'), $this->operator->id);
        $product->update(['active' => false]);
        $customer->update(['active' => false]);
        $this->commerce->cancelSale($sale, $this->operator->id);
        $this->commerce->cancelSale($sale, $this->operator->id);

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, $customer->balance_cents);
        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertNotNull($sale->fresh()->cancelled_at);
        $this->assertSame(1, LedgerEntry::where('reference_type', 'sale_cancel')->count());
        $this->assertSame(1, StockMovement::where('reference_type', 'sale_cancel')->count());
    }

    public function test_cash_sale_and_cancellation_do_not_change_customer_account(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $sale = $this->commerce->sell($this->saleData($product, 1, $customer), $this->operator->id);
        $this->commerce->cancelSale($sale, $this->operator->id);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_sale_snapshots_survive_product_changes(): void
    {
        $product = $this->product(['name' => 'Nombre inicial']);
        $sale = $this->commerce->sell($this->saleData($product), $this->operator->id);
        $product->update(['name' => 'Nombre cambiado', 'price_cents' => 9900]);
        $this->assertSame('Nombre inicial', $sale->items->first()->name);
        $this->assertSame(125050, $sale->fresh()->total_cents);
    }

    public function test_purchase_updates_stock_and_supplier_account_and_is_idempotent(): void
    {
        $product = $this->product(['stock' => 0]);
        $supplier = $this->supplier();
        $data = ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'payment_method' => 'account', 'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => '1000.25']]];
        $purchase = $this->commerce->purchase($data, $this->operator->id);
        $again = $this->commerce->purchase($data, $this->operator->id);
        $this->assertSame($purchase->id, $again->id);
        $this->assertSame('P-000001', $purchase->number);
        $this->assertSame(400100, $purchase->total_cents);
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(100025, $product->fresh()->cost_cents);
        $this->assertSame(400100, $supplier->balance_cents);
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('ledger_entries', 1);

        $data['items'][0]['unit_cost'] = '1000.26';
        $this->assertRejected(fn () => $this->commerce->purchase($data, $this->operator->id), 'request_id');
    }

    public function test_purchase_cancellation_restores_stock_and_account_only_once(): void
    {
        $product = $this->product(['stock' => 2]);
        $supplier = $this->supplier();
        $purchase = $this->commerce->purchase(['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'payment_method' => 'account', 'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => '10.05']]], $this->operator->id);
        $this->commerce->cancelPurchase($purchase, $this->operator->id);
        $this->commerce->cancelPurchase($purchase, $this->operator->id);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $supplier->balance_cents);
        $this->assertSame('cancelled', $purchase->fresh()->status);
        $this->assertSame(1, LedgerEntry::where('reference_type', 'purchase_cancel')->count());
    }

    public function test_purchase_cancellation_rejects_used_inventory_without_partial_changes(): void
    {
        $first = $this->product(['stock' => 0]);
        $second = $this->product(['stock' => 0]);
        $supplier = $this->supplier();
        $purchase = $this->commerce->purchase(['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'payment_method' => 'account', 'items' => [['product_id' => $first->id, 'quantity' => 3, 'unit_cost' => '10.00'], ['product_id' => $second->id, 'quantity' => 3, 'unit_cost' => '20.00']]], $this->operator->id);
        $this->commerce->sell($this->saleData($second, 1), $this->operator->id);
        $this->assertRejected(fn () => $this->commerce->cancelPurchase($purchase, $this->operator->id), 'purchase');
        $this->assertSame(3, $first->fresh()->stock);
        $this->assertSame(2, $second->fresh()->stock);
        $this->assertSame(9000, $supplier->balance_cents);
        $this->assertSame('completed', $purchase->fresh()->status);
        $this->assertSame(0, StockMovement::where('reference_type', 'purchase_cancel')->count());
    }

    public function test_inactive_products_duplicate_lines_and_account_without_customer_are_rejected(): void
    {
        $product = $this->product(['active' => false]);
        $this->assertRejected(fn () => $this->commerce->sell($this->saleData($product), $this->operator->id), 'items');
        $product->update(['active' => true]);
        $data = $this->saleData($product);
        $data['items'][] = ['product_id' => $product->id, 'quantity' => 1];
        $this->assertRejected(fn () => $this->commerce->sell($data, $this->operator->id), 'items');
        $this->assertRejected(fn () => $this->commerce->sell($this->saleData($product, 1, null, 'account'), $this->operator->id), 'customer_id');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_ledger_requires_exactly_one_contact_and_positive_amount(): void
    {
        $customer = $this->customer();
        $supplier = $this->supplier();
        $this->assertRejected(fn () => $this->commerce->ledger(['customer_id' => $customer->id, 'supplier_id' => $supplier->id, 'direction' => 'debit', 'amount' => '1', 'description' => 'No válido'], $this->operator->id), 'customer_id');
        $this->assertRejected(fn () => $this->commerce->ledger(['customer_id' => $customer->id, 'direction' => 'debit', 'amount' => '0', 'description' => 'No válido'], $this->operator->id), 'amount');
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_manual_account_request_is_idempotent_and_payload_changes_are_rejected(): void
    {
        $customer = $this->customer();
        $data = [
            'request_id' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'direction' => 'credit', 'amount' => '75.25', 'description' => 'Pago recibido',
            'payment_method' => 'transfer', 'date' => now()->format('Y-m-d'),
        ];
        $first = $this->commerce->ledger($data, $this->operator->id);
        $again = $this->commerce->ledger($data, $this->operator->id);
        $this->assertSame($first->id, $again->id);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(-7525, $customer->balance_cents);
        $data['amount'] = '76.25';
        $this->assertRejected(fn () => $this->commerce->ledger($data, $this->operator->id), 'request_id');
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertSame(-7525, $customer->balance_cents);
    }

    public function test_ledger_entries_are_immutable(): void
    {
        $customer = $this->customer();
        $entry = $this->commerce->ledger(['customer_id' => $customer->id, 'direction' => 'debit', 'amount' => '20.55', 'description' => 'Saldo inicial'], $this->operator->id);
        try {
            $entry->update(['amount_cents' => 1]);
            $this->fail('La edición de un movimiento debe bloquearse.');
        } catch (LogicException) {
            $this->assertSame(2055, $entry->fresh()->amount_cents);
        }
        try {
            $entry->delete();
            $this->fail('El borrado de un movimiento debe bloquearse.');
        } catch (LogicException) {
            $this->assertDatabaseCount('ledger_entries', 1);
        }
    }

    public function test_money_parses_decimal_strings_exactly_and_formats_argentine_pesos(): void
    {
        $this->assertSame(10, Money::cents('0.10'));
        $this->assertSame(101, Money::cents('1,01'));
        $this->assertSame(123450, Money::cents('1234.5'));
        $this->assertSame('$ 1.234,50', Money::format(123450));
        $this->assertSame('-$ 0,01', Money::format(-1));
        foreach (['1.001', '1,234.56', '-1', 'NaN', '9e4'] as $invalid) {
            $this->assertRejected(fn () => Money::cents($invalid), 'amount');
        }
    }
}
