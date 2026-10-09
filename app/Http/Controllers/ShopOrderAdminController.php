<?php

namespace App\Http\Controllers;

use App\Models\CustomerOrder;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ShopOrderAdminController extends Controller
{
    public function index(Request $request): View
    {
        $query = CustomerOrder::with('user');
        $search = trim($request->string('q')->toString());
        if ($search !== '') $query->where(fn ($query) => $query->where('number', 'like', '%'.$search.'%')->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')));
        if (array_key_exists((string) $request->input('status'), CustomerOrder::STATUSES)) $query->where('status', $request->input('status'));

        return view('shop.admin.orders.index', ['orders' => $query->latest('id')->paginate(20)->withQueryString(), 'statuses' => CustomerOrder::STATUSES]);
    }

    public function update(Request $request, CustomerOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(CustomerOrder::STATUSES))],
            'payment_status' => ['sometimes', 'required', Rule::in(['pending', 'confirmed'])],
            'tracking_code' => ['nullable', 'string', 'max:150'],
            'tracking_url' => ['nullable', 'url:https', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $order, $data) {
            $current = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $status = $data['status'];
            $payment = $data['payment_status'] ?? $current->payment_status;
            if ($current->payment_status === 'confirmed' && $payment !== 'confirmed') throw ValidationException::withMessages(['payment_status' => 'Un pago confirmado no puede volver a pendiente. El registro manual debe conservarse.']);
            if ($current->status === 'cancelled') {
                if ($status !== 'cancelled' || $payment !== $current->payment_status) throw ValidationException::withMessages(['status' => 'Un pedido cancelado no puede reabrirse ni recibir una confirmación de pago.']);

                return;
            }
            $next = ['pending' => 'preparing', 'preparing' => 'shipped', 'shipped' => 'delivered'];
            if ($status !== $current->status && $status !== 'cancelled' && ($next[$current->status] ?? null) !== $status) throw ValidationException::withMessages(['status' => 'Avanzá el pedido en orden: pendiente, preparando, listo para retirar y entregado.']);
            if ($status === 'cancelled') {
                if ($current->status === 'delivered') throw ValidationException::withMessages(['status' => 'Un pedido entregado no puede cancelarse desde este panel.']);
                if ($payment === 'confirmed' || $current->payment_status === 'confirmed') throw ValidationException::withMessages(['payment_status' => 'Este pedido tiene un pago confirmado. Gestioná y registrá la devolución por fuera de este flujo antes de solicitar su cancelación.']);
                if (! $current->stock_released_at) {
                    $items = collect($current->items)->sortBy('product_id');
                    foreach ($items as $item) {
                        $product = Product::whereKey($item['product_id'])->lockForUpdate()->first();
                        if (! $product) throw ValidationException::withMessages(['order' => 'No encontramos un producto del pedido para reponer su stock.']);
                        $product->increment('stock', $item['quantity']);
                        StockMovement::create([
                            'product_id' => $product->id, 'delta' => $item['quantity'], 'reference_type' => 'customer_order_cancel',
                            'reference_id' => $current->id, 'note' => 'Reserva liberada al cancelar '.$current->number.'.', 'user_id' => $request->user()->id,
                        ]);
                    }
                    $current->stock_released_at = now();
                }
                $current->status = 'cancelled'; $current->cancelled_at = now(); $current->cancelled_by = $request->user()->id;
                $current->save();

                return;
            }
            if (in_array($status, ['preparing', 'shipped', 'delivered'], true) && $payment !== 'confirmed') throw ValidationException::withMessages(['payment_status' => 'Registrá explícitamente la confirmación manual del pago antes de preparar o entregar el pedido. Este panel no realiza cobros.']);
            if ($payment === 'confirmed' && $current->payment_status !== 'confirmed') {
                $current->payment_confirmed_at = now(); $current->payment_confirmed_by = $request->user()->id;
            }
            $current->status = $status; $current->payment_status = $payment;
            foreach (['tracking_code', 'tracking_url'] as $field) if (array_key_exists($field, $data)) $current->{$field} = $data[$field] ?: null;
            $current->save();
        }, 3);

        return redirect()->route('shop.admin.orders.index')->with('success', 'Pedido actualizado. Los pagos se registran manualmente; no se hizo ningún cobro externo.');
    }
}
