<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CommerceService
{
    public function sell(array $data, int $userId): Sale
    {
        $data = Validator::make($data, [
            'request_id' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:cash,card,transfer,account'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        $items = $this->normaliseItems($data['items']);
        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
        $hash = $this->requestHash($items, $customerId, $data['payment_method'], $userId);

        try {
            return DB::transaction(function () use ($data, $items, $customerId, $hash, $userId) {
                if ($existing = Sale::where('request_id', $data['request_id'])->first()) {
                    $this->assertRequest($existing->request_hash, $hash);
                    return $existing->load('items', 'customer');
                }
                $customer = $customerId ? Customer::whereKey($customerId)->lockForUpdate()->first() : null;
                if ($customerId && (! $customer || ! $customer->active)) {
                    $this->fail('customer_id', 'El cliente no existe o está inactivo.');
                }
                if ($data['payment_method'] === 'account' && ! $customer) {
                    $this->fail('customer_id', 'Seleccioná un cliente para vender en cuenta corriente.');
                }
                $products = $this->lockProducts($items);
                $total = 0;
                foreach ($items as $item) {
                    $product = $products->get($item['product_id']);
                    if (! $product->active) {
                        $this->fail('items', "El producto {$product->name} está inactivo.");
                    }
                    if ($product->stock < $item['quantity']) {
                        $this->fail('items', "Stock insuficiente de {$product->name}. Disponible: {$product->stock}.");
                    }
                    $total = $this->addSubtotal($total, $product->price_cents, $item['quantity']);
                }
                if ($total <= 0) {
                    $this->fail('items', 'La venta debe tener un total mayor a cero.');
                }
                if ($data['payment_method'] === 'account') {
                    $this->checkCreditLimit($customer, $total);
                }
                $sale = Sale::create([
                    'number' => 'pending-'.$data['request_id'], 'request_id' => $data['request_id'], 'request_hash' => $hash,
                    'customer_id' => $customerId, 'payment_method' => $data['payment_method'],
                    'total_cents' => $total, 'status' => 'completed', 'user_id' => $userId,
                ]);
                $sale->update(['number' => 'S-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT)]);
                foreach ($items as $item) {
                    $product = $products->get($item['product_id']);
                    $quantity = $item['quantity'];
                    // The condition is a second safeguard against overselling on engines without row locks.
                    $changed = Product::whereKey($product->id)->where('stock', '>=', $quantity)->decrement('stock', $quantity);
                    if ($changed !== 1) {
                        $this->fail('items', "El stock de {$product->name} cambió. Actualizá y volvé a intentar.");
                    }
                    $sale->items()->create([
                        'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                        'quantity' => $quantity, 'unit_price_cents' => $product->price_cents,
                        'subtotal_cents' => $product->price_cents * $quantity,
                    ]);
                    $this->recordStock($product->id, -$quantity, 'sale', $sale->id, "Venta {$sale->number}", $userId);
                }
                if ($data['payment_method'] === 'account') {
                    $this->recordLedger($customerId, null, 'debit', $total, 'sale', $sale->id, "Venta {$sale->number}", 'account', $userId);
                }

                return $sale->load('items', 'customer');
            }, 3);
        } catch (QueryException $exception) {
            // A concurrent identical request may win the unique request_id race. Its transaction is complete.
            $existing = Sale::where('request_id', $data['request_id'])->first();
            if (! $existing) { throw $exception; }
            $this->assertRequest($existing->request_hash, $hash);
            return $existing->load('items', 'customer');
        }
    }

    public function purchase(array $data, int $userId): Purchase
    {
        $data = Validator::make($data, [
            'request_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:cash,transfer,account'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'regex:/^[0-9]{1,11}(?:[.,][0-9]{1,2})?$/D'],
        ])->validate();
        $items = $this->normaliseItems($data['items'], true);
        $supplierId = (int) $data['supplier_id'];
        $hash = $this->requestHash($items, $supplierId, $data['payment_method'], $userId);

        try {
            return DB::transaction(function () use ($data, $items, $supplierId, $hash, $userId) {
                if ($existing = Purchase::where('request_id', $data['request_id'])->first()) {
                    $this->assertRequest($existing->request_hash, $hash);
                    return $existing->load('items', 'supplier');
                }
                $supplier = Supplier::whereKey($supplierId)->lockForUpdate()->first();
                if (! $supplier || ! $supplier->active) {
                    $this->fail('supplier_id', 'El proveedor no existe o está inactivo.');
                }
                $products = $this->lockProducts($items);
                $total = 0;
                foreach ($items as $item) {
                    $product = $products->get($item['product_id']);
                    if (! $product->active) { $this->fail('items', "El producto {$product->name} está inactivo."); }
                    if ($product->stock + $item['quantity'] > 2_000_000_000) { $this->fail('items', 'El stock supera el máximo permitido.'); }
                    $total = $this->addSubtotal($total, $item['unit_cost_cents'], $item['quantity']);
                }
                if ($total <= 0) { $this->fail('items', 'La compra debe tener un total mayor a cero.'); }
                $purchase = Purchase::create([
                    'number' => 'pending-'.$data['request_id'], 'request_id' => $data['request_id'], 'request_hash' => $hash,
                    'supplier_id' => $supplierId, 'payment_method' => $data['payment_method'],
                    'total_cents' => $total, 'status' => 'completed', 'user_id' => $userId,
                ]);
                $purchase->update(['number' => 'P-'.str_pad((string) $purchase->id, 6, '0', STR_PAD_LEFT)]);
                foreach ($items as $item) {
                    $product = $products->get($item['product_id']);
                    $product->update(['stock' => $product->stock + $item['quantity'], 'cost_cents' => $item['unit_cost_cents']]);
                    $purchase->items()->create([
                        'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                        'quantity' => $item['quantity'], 'unit_cost_cents' => $item['unit_cost_cents'],
                        'subtotal_cents' => $item['unit_cost_cents'] * $item['quantity'],
                    ]);
                    $this->recordStock($product->id, $item['quantity'], 'purchase', $purchase->id, "Compra {$purchase->number}", $userId);
                }
                if ($data['payment_method'] === 'account') {
                    $this->recordLedger(null, $supplierId, 'debit', $total, 'purchase', $purchase->id, "Compra {$purchase->number}", 'account', $userId);
                }

                return $purchase->load('items', 'supplier');
            }, 3);
        } catch (QueryException $exception) {
            $existing = Purchase::where('request_id', $data['request_id'])->first();
            if (! $existing) { throw $exception; }
            $this->assertRequest($existing->request_hash, $hash);
            return $existing->load('items', 'supplier');
        }
    }

    public function cancelSale(Sale $sale, int $userId): Sale
    {
        return DB::transaction(function () use ($sale, $userId) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status === 'cancelled') { return $sale->load('items', 'customer'); }
            if ($sale->customer_id) { Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail(); }
            $sale->load('items');
            $products = Product::whereIn('id', $sale->items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($sale->items as $item) {
                $product = $products->get($item->product_id);
                if ($product->stock + $item->quantity > 2_000_000_000) { $this->fail('sale', 'La devolución excede el máximo de stock permitido.'); }
                $product->increment('stock', $item->quantity);
                $this->recordStock($product->id, $item->quantity, 'sale_cancel', $sale->id, "Anulación {$sale->number}", $userId);
            }
            if ($sale->payment_method === 'account') {
                $this->recordLedger($sale->customer_id, null, 'credit', $sale->total_cents, 'sale_cancel', $sale->id, "Anulación {$sale->number}", 'account', $userId);
            }
            $sale->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $userId]);
            return $sale->load('items', 'customer');
        }, 3);
    }

    public function cancelPurchase(Purchase $purchase, int $userId): Purchase
    {
        return DB::transaction(function () use ($purchase, $userId) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status === 'cancelled') { return $purchase->load('items', 'supplier'); }
            Supplier::whereKey($purchase->supplier_id)->lockForUpdate()->firstOrFail();
            $purchase->load('items');
            $products = Product::whereIn('id', $purchase->items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($purchase->items as $item) {
                $product = $products->get($item->product_id);
                if ($product->stock < $item->quantity) {
                    $this->fail('purchase', "No se puede anular: faltan unidades de {$product->name} para revertir la compra.");
                }
            }
            foreach ($purchase->items as $item) {
                $product = $products->get($item->product_id);
                if (Product::whereKey($product->id)->where('stock', '>=', $item->quantity)->decrement('stock', $item->quantity) !== 1) {
                    $this->fail('purchase', 'El stock cambió. Volvé a intentar.');
                }
                $this->recordStock($product->id, -$item->quantity, 'purchase_cancel', $purchase->id, "Anulación {$purchase->number}", $userId);
            }
            if ($purchase->payment_method === 'account') {
                $this->recordLedger(null, $purchase->supplier_id, 'credit', $purchase->total_cents, 'purchase_cancel', $purchase->id, "Anulación {$purchase->number}", 'account', $userId);
            }
            $purchase->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $userId]);
            // Current cost is retained: older purchases may have been followed by newer cost updates.
            return $purchase->load('items', 'supplier');
        }, 3);
    }

    public function ledger(array $data, int $userId): LedgerEntry
    {
        $data = Validator::make($data, [
            'request_id' => ['nullable', 'uuid'],
            'customer_id' => ['nullable', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1'],
            'direction' => ['required', 'in:debit,credit'],
            'amount' => ['required', 'regex:/^[0-9]{1,11}(?:[.,][0-9]{1,2})?$/D'],
            'description' => ['required', 'string', 'min:2', 'max:500'],
            'payment_method' => ['nullable', 'in:cash,card,transfer'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ])->validate();
        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
        $supplierId = isset($data['supplier_id']) ? (int) $data['supplier_id'] : null;
        if ((bool) $customerId === (bool) $supplierId) {
            $this->fail('customer_id', 'Seleccioná exactamente un cliente o un proveedor.');
        }
        $amount = Money::cents($data['amount']);
        if ($amount <= 0) { $this->fail('amount', 'El importe debe ser mayor a cero.'); }

        $requestId = ! empty($data['request_id']) ? $data['request_id'] : null;
        $hash = $requestId ? hash('sha256', json_encode([
            'customer_id' => $customerId, 'supplier_id' => $supplierId,
            'direction' => $data['direction'], 'amount_cents' => $amount,
            'description' => $data['description'], 'payment_method' => $data['payment_method'] ?? null,
            'date' => $data['date'] ?? null, 'user_id' => $userId,
        ], JSON_THROW_ON_ERROR)) : null;

        try {
            return DB::transaction(function () use ($data, $customerId, $supplierId, $amount, $userId, $requestId, $hash) {
                $contact = $customerId
                    ? Customer::whereKey($customerId)->lockForUpdate()->first()
                    : Supplier::whereKey($supplierId)->lockForUpdate()->first();
                // Read after the owner lock so two identical account forms see the committed winner.
                if ($requestId && ($existing = LedgerEntry::where('request_id', $requestId)->lockForUpdate()->first())) {
                    $this->assertRequest((string) $existing->request_hash, $hash);
                    return $existing;
                }
                // Inactive contacts can still settle their existing account; only new debits are blocked.
                if (! $contact || (! $contact->active && $data['direction'] === 'debit')) {
                    $this->fail('customer_id', 'El titular no existe o está inactivo para nuevas deudas.');
                }
                if ($contact instanceof Customer && $data['direction'] === 'debit') {
                    $this->checkCreditLimit($contact, $amount);
                }
                return $this->recordLedger($customerId, $supplierId, $data['direction'], $amount, null, null, $data['description'], $data['payment_method'] ?? null, $userId, $data['date'] ?? null, $requestId, $hash);
            }, 3);
        } catch (QueryException $exception) {
            $existing = $requestId ? LedgerEntry::where('request_id', $requestId)->first() : null;
            if (! $existing) { throw $exception; }
            $this->assertRequest((string) $existing->request_hash, $hash);
            return $existing;
        }
    }

    private function normaliseItems(array $items, bool $purchase = false): array
    {
        $normalised = [];
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            if (isset($normalised[$id])) { $this->fail('items', 'Un producto no puede estar repetido. Sumá las cantidades en una sola línea.'); }
            $normalised[$id] = ['product_id' => $id, 'quantity' => (int) $item['quantity']];
            if ($purchase) { $normalised[$id]['unit_cost_cents'] = Money::cents($item['unit_cost']); }
        }
        ksort($normalised, SORT_NUMERIC);
        return array_values($normalised);
    }

    private function lockProducts(array $items): Collection
    {
        $ids = array_column($items, 'product_id');
        $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== count($ids)) { $this->fail('items', 'Uno de los productos ya no existe. Actualizá la lista.'); }
        return $products;
    }

    private function addSubtotal(int $total, int $price, int $quantity): int
    {
        if ($price < 0 || $price > intdiv(Money::MAX_CENTS - $total, $quantity)) {
            $this->fail('items', 'El total supera el máximo permitido.');
        }
        return $total + $price * $quantity;
    }

    private function checkCreditLimit(Customer $customer, int $amount): void
    {
        // A locking read avoids a stale repeatable-read snapshot established by the request-id lookup.
        // Every account writer also holds this customer's row lock.
        $balance = $customer->ledgerEntries()->lockForUpdate()->get(['direction', 'amount_cents'])
            ->reduce(fn (int $carry, LedgerEntry $entry) => $carry + ($entry->direction === 'debit' ? $entry->amount_cents : -$entry->amount_cents), 0);
        if ($customer->credit_limit_cents > 0 && $balance + $amount > $customer->credit_limit_cents) {
            $this->fail('customer_id', 'La operación supera el límite de cuenta corriente del cliente.');
        }
    }

    private function requestHash(array $items, ?int $contactId, string $method, int $userId): string
    {
        return hash('sha256', json_encode(['items' => $items, 'contact_id' => $contactId, 'payment_method' => $method, 'user_id' => $userId], JSON_THROW_ON_ERROR));
    }

    private function assertRequest(string $stored, string $incoming): void
    {
        if (! hash_equals($stored, $incoming)) { $this->fail('request_id', 'Este identificador ya fue usado con otra operación. Abrí una nueva operación.'); }
    }

    private function recordStock(int $productId, int $delta, string $referenceType, int $referenceId, string $note, int $userId): StockMovement
    {
        return StockMovement::create(['product_id' => $productId, 'delta' => $delta, 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'note' => $note, 'user_id' => $userId]);
    }

    private function recordLedger(?int $customerId, ?int $supplierId, string $direction, int $amount, ?string $referenceType, ?int $referenceId, string $description, ?string $method, int $userId, ?string $date = null, ?string $requestId = null, ?string $requestHash = null): LedgerEntry
    {
        return LedgerEntry::create([
            'customer_id' => $customerId, 'supplier_id' => $supplierId, 'direction' => $direction,
            'request_id' => $requestId, 'request_hash' => $requestHash,
            'amount_cents' => $amount, 'reference_type' => $referenceType, 'reference_id' => $referenceId,
            'description' => $description, 'payment_method' => $method, 'date' => $date ?? now(), 'user_id' => $userId,
        ]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
