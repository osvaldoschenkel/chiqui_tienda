<?php

namespace App\Services;

use App\Models\CustomerOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class Zipnova
{
    private function api()
    {
        if (! IntegrationSettings::ready('zip')) {
            throw ValidationException::withMessages(['shipping' => 'Configurá Zipnova antes de cotizar.']);
        }

        return Http::withBasicAuth(IntegrationSettings::get('zip_token'), IntegrationSettings::get('zip_secret'))->acceptJson()->timeout(20)->connectTimeout(5);
    }

    public function quote(array $items, array $destination): array
    {
        $units = [];
        $declared = 0;
        foreach ($items as $item) {
            foreach (['weight_grams', 'height_cm', 'width_cm', 'length_cm'] as $k) {
                if (($item[$k] ?? 0) <= 0) {
                    throw ValidationException::withMessages(['shipping' => 'Completá peso y medidas de todos los productos antes de cotizar.']);
                }
            }for ($n = 0; $n < $item['quantity']; $n++) {
                $units[] = ['weight' => max(10, $item['weight_grams']), 'height' => ceil($item['height_cm']), 'width' => ceil($item['width_cm']), 'length' => ceil($item['length_cm']), 'description' => $item['name']];
            }
            $declared += $item['quantity'] * $item['unit_price_cents'];
        }
        if (count($units) > 1000) {
            throw ValidationException::withMessages(['shipping' => 'El envío admite hasta 1000 unidades.']);
        }
        $body = ['account_id' => (int) IntegrationSettings::get('zip_account'), 'origin_id' => (int) IntegrationSettings::get('zip_origin'), 'source' => 'chiqui', 'declared_value' => $declared / 100, 'destination' => ['zipcode' => $destination['postcode'], 'city' => $destination['city'], 'state' => $destination['state']], 'items' => $units, 'type_packaging' => 'none', 'sort_by' => 'price'];
        $result = $this->api()->post('https://api.zipnova.com.ar/v2/shipments/quote', $body)->throw()->json();
        $options = [];
        foreach (($result['all_results'] ?? $result['results'] ?? []) as $quote) {
            if (($quote['selectable'] ?? false) !== true || ! is_numeric($quote['amounts']['price_incl_tax'] ?? null)) {
                continue;
            }
            $amount = (float) $quote['amounts']['price_incl_tax'];
            if (! is_finite($amount) || $amount < 0) {
                continue;
            }
            if (empty($quote['carrier']['id']) || empty($quote['service_type']['code']) || empty($quote['logistic_type'])) {
                continue;
            }
            $selection = array_replace($body, ['carrier_id' => (int) $quote['carrier']['id'], 'service_type' => $quote['service_type']['code'], 'logistic_type' => $quote['logistic_type']]);
            $points = $selection['service_type'] === 'pickup_point' ? ($quote['pickup_points'] ?? []) : [null];
            foreach ($points as $point) {
                $selected = $selection;
                if ($point) {
                    if (empty($point['point_id'])) {
                        continue;
                    }
                    $selected['destination']['point_id'] = $point['point_id'];
                }
                $options[] = ['label' => ($quote['carrier']['name'] ?? 'Correo').' · '.($quote['service_type']['name'] ?? $selection['service_type']).($point ? ' · '.($point['description'] ?? 'Sucursal') : ''), 'cents' => (int) round($amount * 100), 'selection' => $selected];
            }
        }if (! $options) {
            throw ValidationException::withMessages(['shipping' => 'No hay servicios disponibles para ese destino.']);
        }

        return $options;
    }

    public function fetchShipment(CustomerOrder $order, string $id): array
    {
        if (! preg_match('/^\d{1,20}$/', $id)) {
            throw ValidationException::withMessages(['shipping' => 'ID de envío inválido.']);
        }
        $data = $this->api()->get('https://api.zipnova.com.ar/v2/shipments/'.$id)->throw()->json();
        if ((string) ($data['id'] ?? '') !== $id || ($data['external_id'] ?? '') !== 'CHIQUI-'.$order->id || (int) ($data['account_id'] ?? 0) !== (int) IntegrationSettings::get('zip_account')) {
            throw ValidationException::withMessages(['shipping' => 'Ese envío no corresponde al pedido o cuenta.']);
        }
        $url = $data['tracking'] ?? $data['tracking_external'] ?? null;
        if ($url && parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $url = null;
        }

        return ['shipment_id' => $id, 'shipment_state' => (string) ($data['status'] ?? 'created'), 'tracking_code' => $data['carrier_tracking_id'] ?? $data['delivery_id'] ?? null, 'tracking_url' => $url];
    }

    public function create(CustomerOrder $order): array
    {
        $body = $order->shipping_selection;
        if (! $body) {
            throw ValidationException::withMessages(['shipping' => 'Este pedido no tiene una cotización de envío.']);
        }
        if ((int) $body['account_id'] !== (int) IntegrationSettings::get('zip_account') || (int) $body['origin_id'] !== (int) IntegrationSettings::get('zip_origin')) {
            throw ValidationException::withMessages(['shipping' => 'La cuenta o el origen cambió. Revisá la cotización antes de crear el envío.']);
        }
        $a = $order->address;
        $destination = ['name' => $a['name'], 'document' => $order->billing['document'], 'email' => $order->user->email, 'phone' => $a['phone'], 'zipcode' => $a['postcode'], 'city' => $a['city'], 'state' => $a['state']];
        if (isset($body['destination']['point_id'])) {
            $destination['point_id'] = $body['destination']['point_id'];
            unset($destination['city'],$destination['state']);
        } else {
            $destination += ['street' => $a['street'], 'street_number' => $a['number'], 'street_extras' => $a['floor'] ?? ''];
        }
        $body['destination'] = $destination;
        $body['external_id'] = 'CHIQUI-'.$order->id;
        $body['process_immediately'] = 0;
        $data = $this->api()->post('https://api.zipnova.com.ar/v2/shipments', $body)->throw()->json();
        if (empty($data['id'])) {
            throw ValidationException::withMessages(['shipping' => 'Respuesta de envío incompleta. Consultá Zipnova antes de reintentar.']);
        }
        $url = $data['tracking'] ?? $data['tracking_external'] ?? null;
        if ($url && parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $url = null;
        }

        return ['shipment_id' => (string) $data['id'], 'shipment_state' => 'created', 'tracking_code' => $data['carrier_tracking_id'] ?? $data['delivery_id'] ?? null, 'tracking_url' => $url];
    }
}
