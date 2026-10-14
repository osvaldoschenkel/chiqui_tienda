<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ShippingQuote;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnlineOrders
{
    public function cancel(CustomerOrder $order, int $actor): void
    {
        DB::transaction(function () use ($order, $actor) {
            $o = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($o->payment_status === 'confirmed' || $o->sale_id || $o->shipment_id || $o->shipment_state === 'unknown') {
                throw ValidationException::withMessages(['order' => 'El pedido tiene cobro o envío. Gestioná la devolución antes de cancelar.']);
            }if ($o->status === 'cancelled') {
                return;
            }if (! $o->stock_released_at) {
                foreach (collect($o->items)->sortBy('product_id') as $i) {
                    Product::whereKey($i['product_id'])->lockForUpdate()->firstOrFail()->increment('stock', $i['quantity']);
                    StockMovement::create(['product_id' => $i['product_id'], 'delta' => $i['quantity'], 'reference_type' => 'customer_order_cancel', 'reference_id' => $o->id, 'note' => 'Reserva liberada '.$o->number, 'user_id' => $actor]);
                }
            }
            $o->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor, 'stock_released_at' => now(), 'payment_url' => null]);
        }, 3);
    }

    public function applyPayment(array $payment): bool
    {
        $id = (string) ($payment['external_reference'] ?? '');
        if (! ctype_digit($id)) {
            return false;
        }

        return DB::transaction(function () use ($payment, $id) {
            $o = CustomerOrder::whereKey($id)->lockForUpdate()->first();
            if (! $o || $o->payment_method !== 'mercadopago') {
                return false;
            }
            if (($payment['currency_id'] ?? null) !== 'ARS' || (int) round((float) ($payment['transaction_amount'] ?? 0) * 100) !== $o->total_cents) {
                throw ValidationException::withMessages(['payment' => 'El importe o la moneda del pago no coincide.']);
            }
            $status = $payment['status'] ?? '';
            $paymentId = (string) ($payment['id'] ?? '');
            if (! preg_match('/^\d{1,32}$/', $paymentId)) {
                return false;
            }
            if (in_array($status, ['refunded', 'charged_back'], true) && $o->payment_id === $paymentId) {
                $o->update(['payment_status' => $status, 'payment_review' => 'Pago revertido: revisar devolución, stock y nota de crédito.']);
                if ($o->sale_id) {
                    Sale::whereKey($o->sale_id)->update(['status' => 'cancelled']);
                }

                return false;
            }
            if ($status !== 'approved') {
                return false;
            }
            if ($o->payment_status === 'confirmed') {
                if ($o->payment_id !== $paymentId) {
                    $o->update(['payment_review' => 'Se recibió otro pago aprobado para el mismo pedido: revisar devolución.']);
                }

                return true;
            }
            if (in_array($o->payment_status, ['refunded', 'charged_back'], true)) {
                return false;
            }
            if ($o->status === 'cancelled') {
                $o->update(['payment_review' => 'Pago recibido después de cancelar: gestionar devolución.', 'payment_id' => $paymentId]);

                return false;
            }
            $o->update(['payment_status' => 'confirmed', 'payment_id' => $paymentId, 'payment_confirmed_at' => now(), 'reservation_expires_at' => null]);
            $this->bookSale($o);

            return true;
        }, 3);
    }

    public function bookSale(CustomerOrder $o): void
    {
        if ($o->sale_id || $o->payment_status !== 'confirmed') {
            return;
        }
        $document = $o->billing['document'] ?? null;
        $customer = $document ? Customer::firstOrCreate(['document' => $document], ['name' => $o->billing['name'] ?? $o->user->name, 'email' => $o->user->email, 'active' => true]) : Customer::firstOrCreate(['email' => $o->user->email], ['name' => $o->user->name, 'active' => true]);
        $sale = Sale::create(['number' => 'WEB-'.$o->number, 'request_id' => $o->request_id, 'request_hash' => $o->request_hash, 'customer_id' => $customer->id, 'payment_method' => $o->payment_method === 'mercadopago' ? 'card' : 'cash', 'shipping_cents' => $o->shipping_cents, 'total_cents' => $o->total_cents, 'status' => 'completed', 'user_id' => $o->user_id]);
        foreach ($o->items as $i) {
            $sale->items()->create(['product_id' => $i['product_id'], 'name' => $i['name'], 'sku' => $i['sku'], 'quantity' => $i['quantity'], 'unit_price_cents' => $i['unit_price_cents'], 'subtotal_cents' => $i['quantity'] * $i['unit_price_cents']]);
        }
        $o->update(['sale_id' => $sale->id]); // Inventory was already reserved at checkout; never decrement twice.
    }

    public function expire(): void
    {
        CustomerOrder::where('payment_method', 'mercadopago')->where('payment_status', 'pending')->where('status', 'pending')->whereNotNull('reservation_expires_at')->where('reservation_expires_at', '<', now())->orderBy('id')->chunkById(50, function ($orders) {
            foreach ($orders as $o) {
                try {
                    foreach (app(MercadoPago::class)->search($o) as $p) {
                        $this->applyPayment($p);
                    }
                    $o->refresh();
                    if ($o->payment_status !== 'confirmed') {
                        $this->cancel($o, $o->user_id);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
        ShippingQuote::where('expires_at', '<', now()->subDay())->delete();
    }
}
