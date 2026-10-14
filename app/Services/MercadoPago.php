<?php

namespace App\Services;

use App\Models\CustomerOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class MercadoPago
{
    private function api()
    {
        if (! IntegrationSettings::ready('mp')) {
            throw ValidationException::withMessages(['payment_method' => 'Configurá Mercado Pago antes de cobrar.']);
        }

        return Http::withToken(IntegrationSettings::get('mp_token'))->acceptJson()->timeout(20)->connectTimeout(5);
    }

    public function preference(CustomerOrder $order): array
    {
        $items = array_map(fn ($i) => ['id' => (string) $i['product_id'], 'title' => $i['name'], 'quantity' => $i['quantity'], 'currency_id' => 'ARS', 'unit_price' => $i['unit_price_cents'] / 100], $order->items);
        $result = $this->api()->withHeaders(['X-Idempotency-Key' => 'chiqui-preference-'.$order->id])->post('https://api.mercadopago.com/checkout/preferences', [
            'items' => $items, 'payer' => ['email' => $order->user->email], 'external_reference' => (string) $order->id,
            'shipments' => ['mode' => 'not_specified', 'cost' => $order->shipping_cents / 100, 'free_shipping' => $order->shipping_cents === 0],
            'notification_url' => route('commerce.mp.webhook'), 'back_urls' => ['success' => route('shop.orders.show', $order), 'failure' => route('shop.orders.show', $order), 'pending' => route('shop.orders.show', $order)], 'auto_return' => 'approved',
        ])->throw()->json();
        $url = str_starts_with(IntegrationSettings::get('mp_token'), 'TEST-') ? ($result['sandbox_init_point'] ?? null) : ($result['init_point'] ?? null);
        $host = parse_url($url ?? '', PHP_URL_HOST);
        if (! is_string($host) || ! preg_match('/(^|\.)mercadopago\.com(\.ar)?$/', $host) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages(['payment_method' => 'Mercado Pago no devolvió un enlace de pago válido.']);
        }

        return ['preference_id' => (string) $result['id'], 'payment_url' => $url];
    }

    public function verify(Request $r): ?string
    {
        $id = (string) $r->query('data_id', $r->query('data.id', '')); // PHP normalizes dots in query keys.
        if (! preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $id)) {
            return null;
        }
        $raw = $r->server('QUERY_STRING', '');
        if (preg_match_all('/(?:^|&)data(?:\.|%2[eE]|_)id=/', $raw) !== 1) {
            return null;
        }
        $requestId = $r->header('x-request-id');
        $parts = [];
        foreach (explode(',', $r->header('x-signature', '')) as $part) {
            if (! preg_match('/^\s*(ts|v1)=([^,\s]+)\s*$/', $part, $m) || isset($parts[$m[1]])) {
                return null;
            }
            $parts[$m[1]] = $m[2];
        }
        if (! $requestId || preg_match('/[;\x00-\x1f]/', $requestId) || ! preg_match('/^\d{10,13}$/', $parts['ts'] ?? '') || ! preg_match('/^[a-f0-9]{64}$/i', $parts['v1'] ?? '')) {
            return null;
        }
        $timestamp = strlen($parts['ts']) === 13 ? (int) $parts['ts'] / 1000 : (int) $parts['ts'];
        if (abs(time() - $timestamp) > 300) {
            return null;
        }
        $secret = IntegrationSettings::get('mp_webhook_secret');
        if (! $secret) {
            return null;
        }
        $hash = hash_hmac('sha256', 'id:'.strtolower($id).';request-id:'.$requestId.';ts:'.$parts['ts'].';', $secret);

        return hash_equals($hash, strtolower($parts['v1'])) ? $id : null;
    }

    public function payment(string $id): array
    {
        if (! preg_match('/^\d{1,32}$/', $id)) {
            throw ValidationException::withMessages(['payment' => 'Identificador de pago inválido.']);
        }
        $p = $this->api()->get('https://api.mercadopago.com/v1/payments/'.$id)->throw()->json();
        if ((string) ($p['id'] ?? '') !== $id) {
            throw ValidationException::withMessages(['payment' => 'El proveedor devolvió otro pago.']);
        }

        return $p;
    }

    public function search(CustomerOrder $order): array
    {
        return $this->api()->get('https://api.mercadopago.com/v1/payments/search', ['external_reference' => (string) $order->id, 'limit' => 50, 'sort' => 'date_created', 'criteria' => 'desc'])->throw()->json('results') ?? [];
    }
}
