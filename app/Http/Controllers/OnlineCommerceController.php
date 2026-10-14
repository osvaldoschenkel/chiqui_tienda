<?php

namespace App\Http\Controllers;

use App\Models\CustomerOrder;
use App\Models\ShippingQuote;
use App\Services\ArcaInvoices;
use App\Services\MercadoPago;
use App\Services\OnlineOrders;
use App\Services\ShippingCart;
use App\Services\Zipnova;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnlineCommerceController extends Controller
{
    public function quote(Request $r, Zipnova $zip)
    {
        $destination = $r->validate(['postcode' => 'required|string|max:20', 'city' => 'required|string|max:100', 'state' => 'required|string|max:100']);
        $cart = $r->session()->get('shop.cart', []);
        abort_unless(is_array($cart) && count($cart) > 0 && count($cart) <= 30, 422);
        foreach ($cart as $id => $quantity) {
            abort_unless(ctype_digit((string) $id) && is_int($quantity) && $quantity >= 1 && $quantity <= 100, 422);
        }
        $items = ShippingCart::items($cart);
        $options = $zip->quote($items, $destination);
        $quote = ShippingQuote::create(['id' => (string) Str::uuid(), 'user_id' => $r->user()->id, 'cart_hash' => ShippingCart::hash($items, $destination), 'destination' => $destination, 'options' => $options, 'expires_at' => now()->addMinutes(15)]);

        return response()->json(['options' => array_map(fn ($o, $i) => ['id' => $quote->id.':'.$i, 'label' => $o['label'], 'cents' => $o['cents']], $options, array_keys($options))]);
    }

    public function pay(Request $r, CustomerOrder $order, MercadoPago $mp)
    {
        abort_unless($order->user_id === $r->user()->id, 404);

        return Cache::lock('online-payment-'.$order->id, 60)->block(5, function () use ($order, $mp) {
            $order->refresh();
            if ($order->status === 'cancelled' || $order->payment_method !== 'mercadopago' || $order->payment_status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'El pedido no admite un nuevo pago.']);
            }if ($order->reservation_expires_at?->isPast()) {
                throw ValidationException::withMessages(['payment' => 'La reserva venció. Esperá la conciliación o consultá a la tienda.']);
            }if (! $order->payment_url) {
                $order->update($mp->preference($order));
            }

            return redirect()->away($order->payment_url);
        });
    }

    public function webhook(Request $r, MercadoPago $mp, OnlineOrders $orders)
    {
        $id = $mp->verify($r);
        abort_unless($id, 401);
        $orders->applyPayment($mp->payment($id));

        return response()->json(['received' => true]);
    }

    public function reconcile(CustomerOrder $order, MercadoPago $mp, OnlineOrders $orders)
    {
        foreach ($mp->search($order) as $p) {
            $orders->applyPayment($p);
        }

        return back()->with('success', 'Pago consultado directamente en Mercado Pago.');
    }

    public function shipment(CustomerOrder $order, Zipnova $zip)
    {
        return Cache::lock('online-shipment-'.$order->id, 90)->block(5, function () use ($order, $zip) {
            $order->refresh();
            if ($order->shipment_id) {
                return back()->with('success', 'El envío ya estaba generado.');
            }if (in_array($order->status, ['cancelled', 'delivered'], true) || $order->delivery_method !== 'zipnova') {
                throw ValidationException::withMessages(['shipping' => 'El pedido no admite un envío.']);
            }if ($order->payment_status !== 'confirmed' && $order->payment_method !== 'cod') {
                throw ValidationException::withMessages(['shipping' => 'Confirmá el pago antes de generar el envío.']);
            }if ($order->shipment_state === 'unknown') {
                throw ValidationException::withMessages(['shipping' => 'El intento anterior tiene resultado incierto. Verificá CHIQUI-'.$order->id.' en Zipnova antes de registrar el envío existente.']);
            }DB::transaction(function () use ($order) {
                $current = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (in_array($current->status, ['cancelled', 'delivered'], true)) {
                    throw ValidationException::withMessages(['shipping' => 'El pedido cambió de estado.']);
                }
                $current->update(['shipment_state' => 'unknown']);
            });
            try {
                $order->update($zip->create($order));
            } catch (\Throwable $e) {
                report($e);
                throw ValidationException::withMessages(['shipping' => 'No se confirmó el resultado del envío. Revisá Zipnova antes de reintentar para evitar duplicados.']);
            }

            return back()->with('success', 'Envío creado. Revisá documentación y despacho en Zipnova.');
        });
    }

    public function tracking(Request $r, CustomerOrder $order, Zipnova $zip)
    {
        $data = $r->validate(['shipment_id' => ['nullable', 'regex:/^\d{1,20}$/']]);

        return Cache::lock('online-shipment-'.$order->id, 90)->block(5, function () use ($order, $data, $zip) {
            $order->refresh();
            $id = $order->shipment_id ?? $data['shipment_id'] ?? null;
            if (! $id) {
                throw ValidationException::withMessages(['shipping' => 'Ingresá el ID del envío existente en Zipnova.']);
            }
            $update = $zip->fetchShipment($order, $id);
            DB::transaction(function () use ($order, $update) {
                $current = CustomerOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($current->status === 'cancelled') {
                    throw ValidationException::withMessages(['shipping' => 'El pedido está cancelado.']);
                }if ($update['shipment_state'] === 'delivered' && $current->payment_status === 'confirmed') {
                    $update['status'] = 'delivered';
                } elseif (in_array($update['shipment_state'], ['in_transit', 'shipped', 'out_for_delivery'], true) && in_array($current->status, ['pending', 'preparing'], true)) {
                    $update['status'] = 'shipped';
                }
                $current->update($update);
            });

            return back()->with('success', 'Envío verificado y seguimiento actualizado.');
        });
    }

    public function invoice(CustomerOrder $order, ArcaInvoices $arca)
    {
        $arca->issue($order);

        return back()->with('success', 'Comprobante autorizado y disponible para el comprador.');
    }

    public function download(Request $r, CustomerOrder $order)
    {
        abort_unless($order->user_id === $r->user()->id || $r->user()->isAdmin(), 404);
        $invoice = $order->invoice;
        abort_unless($invoice && $invoice->status === 'authorized', 404);
        $detail = $invoice->request['FeDetReq']['FECAEDetRequest'];
        $qr = ['ver' => 1, 'fecha' => Carbon::createFromFormat('Ymd', $detail['CbteFch'])->format('Y-m-d'), 'cuit' => (int) $invoice->request['issuer']['cuit'], 'ptoVta' => $invoice->point_of_sale, 'tipoCmp' => $invoice->voucher_type, 'nroCmp' => $invoice->voucher_number, 'importe' => $order->total_cents / 100, 'moneda' => 'PES', 'ctz' => 1, 'tipoDocRec' => $detail['DocTipo'], 'nroDocRec' => $detail['DocNro'], 'tipoCodAut' => 'E', 'codAut' => (int) $invoice->cae];
        $renderer = new ImageRenderer(new RendererStyle(180), new SvgImageBackEnd);
        $qrImage = 'data:image/svg+xml;base64,'.base64_encode((new Writer($renderer))->writeString('https://www.afip.gob.ar/fe/qr/?p='.base64_encode(json_encode($qr))));

        return Pdf::loadView('invoices.show', compact('order', 'invoice', 'qrImage'))->download('factura-'.$order->number.'.pdf');
    }
}
