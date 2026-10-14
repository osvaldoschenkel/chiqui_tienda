<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\FiscalInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ArcaInvoices
{
    public static function validCuit(string $cuit): bool
    {
        if (! preg_match('/^\d{11}$/', $cuit)) {
            return false;
        }
        $sum = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $weight) {
            $sum += (int) $cuit[$i] * $weight;
        }
        $check = 11 - $sum % 11;
        $check = $check === 11 ? 0 : ($check === 10 ? 9 : $check);

        return (int) $cuit[10] === $check;
    }

    public function payload(CustomerOrder $order, int $type, int $pos, int $number): array
    {
        $billing = $order->billing;
        if (! $billing || empty($billing['name'])) {
            throw ValidationException::withMessages(['arca' => 'El comprador debe completar sus datos fiscales.']);
        }
        $document = (string) ($billing['document'] ?? '');
        $condition = (int) ($billing['condition'] ?? 5);
        if (! preg_match('/^\d{7,11}$/', $document)) {
            throw ValidationException::withMessages(['arca' => 'Documento del comprador inválido.']);
        }
        if (strlen($document) === 11 && ! self::validCuit($document)) {
            throw ValidationException::withMessages(['arca' => 'La CUIT del comprador no es válida.']);
        }
        if (($type === 1 || in_array($condition, [1, 6], true)) && (strlen($document) !== 11 || ! self::validCuit($document))) {
            throw ValidationException::withMessages(['arca' => 'La condición fiscal seleccionada requiere CUIT válida.']);
        }
        $net = 0;
        $vat = 0;
        $exempt = 0;
        $groups = [];
        $items = $order->items;
        if ($order->shipping_cents) {
            $items[] = ['quantity' => 1, 'unit_price_cents' => $order->shipping_cents, 'vat_rate' => 21];
        }
        $groupTotals = [];
        foreach ($items as $i) {
            $rate = (string) ($i['vat_rate'] ?? 21);
            if (! in_array((float) $rate, [0.0, 10.5, 21.0, 27.0], true)) {
                throw ValidationException::withMessages(['arca' => 'Hay una alícuota no admitida.']);
            }
            $groupTotals[$rate] = ($groupTotals[$rate] ?? 0) + $i['quantity'] * $i['unit_price_cents'];
        }
        if ($type !== 11) {
            foreach ($groupTotals as $rate => $gross) {
                if ((float) $rate === 0.0) {
                    $exempt += $gross;

                    continue;
                }
                $base = (int) round($gross / (1 + (float) $rate / 100));
                $tax = $gross - $base;
                $net += $base;
                $vat += $tax;
                $groups[] = ['Id' => match ((float) $rate) {
                    10.5 => 4,21.0 => 5,27.0 => 6
                }, 'BaseImp' => $base / 100, 'Importe' => $tax / 100];
            }
        }
        if ($type === 11) {
            $net = $order->total_cents;
        }
        $detail = ['Concepto' => 1, 'DocTipo' => strlen($document) === 11 ? 80 : 96, 'DocNro' => (int) $document, 'CbteDesde' => $number, 'CbteHasta' => $number, 'CbteFch' => now('America/Argentina/Buenos_Aires')->format('Ymd'), 'ImpTotal' => $order->total_cents / 100, 'ImpTotConc' => 0, 'ImpNeto' => $net / 100, 'ImpOpEx' => $exempt / 100, 'ImpTrib' => 0, 'ImpIVA' => $vat / 100, 'MonId' => 'PES', 'MonCotiz' => 1, 'CondicionIVAReceptorId' => $condition];
        if ($groups) {
            $detail['Iva'] = ['AlicIva' => $groups];
        }

        return ['FeCabReq' => ['CantReg' => 1, 'PtoVta' => $pos, 'CbteTipo' => $type], 'FeDetReq' => ['FECAEDetRequest' => $detail]];
    }

    public function issue(CustomerOrder $order): FiscalInvoice
    {
        if (! IntegrationSettings::ready('arca')) {
            throw ValidationException::withMessages(['arca' => 'Completá la configuración ARCA antes de facturar.']);
        }
        $env = IntegrationSettings::get('arca_environment', 'homologation');
        $cuit = (string) IntegrationSettings::get('arca_cuit');
        $pos = (int) IntegrationSettings::get('arca_pos');
        if (! self::validCuit($cuit)) {
            throw ValidationException::withMessages(['arca' => 'La CUIT del emisor no es válida.']);
        }
        $type = IntegrationSettings::get('arca_condition') === 'ri' ? (in_array((int) ($order->billing['condition'] ?? 5), [1, 6], true) ? 1 : 6) : 11;

        return Cache::lock('arca-emission-'.hash('sha256', $env.$cuit.$pos), 180)->block(10, function () use ($order, $env, $cuit, $pos, $type) {
            $order->refresh();
            if ($order->status === 'cancelled' || $order->payment_status !== 'confirmed') {
                throw ValidationException::withMessages(['arca' => 'Facturá únicamente pedidos con pago confirmado.']);
            }
            $invoice = $order->invoice;
            if ($invoice?->status === 'authorized') {
                return $invoice;
            }
            if ($invoice && ($invoice->environment !== $env || $invoice->point_of_sale !== $pos || ($invoice->request['issuer']['cuit'] ?? null) !== $cuit)) {
                throw ValidationException::withMessages(['arca' => 'La configuración del emisor cambió. Resolvé primero el comprobante pendiente en su ambiente original.']);
            }
            $pending = FiscalInvoice::where('issuer_cuit', $cuit)->where('environment', $env)->where('point_of_sale', $pos)->where('status', '!=', 'authorized')->where('customer_order_id', '!=', $order->id)->exists();
            if ($pending) {
                throw ValidationException::withMessages(['arca' => 'Hay una emisión pendiente. Resolvela antes de asignar otro número.']);
            }
            $client = app(ArcaClient::class);
            if (! $invoice) {
                $number = $client->last($pos, $type) + 1;
                $request = $this->payload($order, $type, $pos, $number);
                $request['issuer'] = ['cuit' => $cuit, 'name' => IntegrationSettings::get('arca_name'), 'address' => IntegrationSettings::get('arca_address'), 'condition' => IntegrationSettings::get('arca_condition'), 'iibb' => IntegrationSettings::get('arca_iibb'), 'activity_date' => IntegrationSettings::get('arca_activity_date')];
                $invoice = FiscalInvoice::create(['customer_order_id' => $order->id, 'issuer_cuit' => $cuit, 'environment' => $env, 'point_of_sale' => $pos, 'voucher_type' => $type, 'voucher_number' => $number, 'request' => $request]);
            }
            try {
                $existing = $client->consult($pos, $invoice->voucher_type, $invoice->voucher_number);
                $detail = $invoice->request['FeDetReq']['FECAEDetRequest'];
                if ($existing) {
                    if ((int) ($existing['DocNro'] ?? -1) !== $detail['DocNro'] || (int) round((float) ($existing['ImpTotal'] ?? -1) * 100) !== $order->total_cents || ($existing['CbteFch'] ?? null) !== $detail['CbteFch']) {
                        throw new \RuntimeException('El número está ocupado por otro comprobante. No se reemitió.');
                    }
                    $cae = $existing['CodAutorizacion'] ?? '';
                    $expires = $existing['FchVto'] ?? '';
                    $response = $existing;
                } else {
                    $request = $invoice->request;
                    unset($request['issuer']);
                    $response = $client->authorize($request);
                    $result = $response['FeDetResp']['FECAEDetResponse'] ?? [];
                    if (isset($result[0])) {
                        $result = $result[0];
                    }if (($result['Resultado'] ?? '') !== 'A') {
                        throw new \RuntimeException('ARCA rechazó el comprobante: '.json_encode($response['Errors'] ?? $result['Observaciones'] ?? [], JSON_UNESCAPED_UNICODE));
                    }
                    $cae = $result['CAE'] ?? '';
                    $expires = $result['CAEFchVto'] ?? '';
                }
                if (! preg_match('/^\d{14}$/', (string) $cae) || ! preg_match('/^\d{8}$/', (string) $expires)) {
                    throw new \RuntimeException('Respuesta fiscal incompleta. Consultá antes de reintentar.');
                }
                $invoice->update(['status' => 'authorized', 'cae' => $cae, 'cae_expires_at' => Carbon::createFromFormat('Ymd', $expires)->format('Y-m-d'), 'response' => $response, 'last_error' => null]);

                return $invoice;
            } catch (\Throwable $e) {
                $invoice->update(['last_error' => mb_substr($e->getMessage(), 0, 3000)]);
                report($e);
                throw ValidationException::withMessages(['arca' => 'No se confirmó la emisión. '.$invoice->last_error.' El reintento consultará el mismo número antes de emitir.']);
            }
        });
    }
}
