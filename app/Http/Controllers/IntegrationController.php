<?php

namespace App\Http\Controllers;

use App\Services\IntegrationSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntegrationController extends Controller
{
    public function edit()
    {
        return view('integrations.edit', ['settings' => IntegrationSettings::allValues()]);
    }

    public function update(Request $r)
    {
        $data = $r->validate([
            'mp_token' => ['nullable', 'string', 'max:4096'], 'mp_webhook_secret' => ['nullable', 'string', 'max:4096'],
            'zip_token' => ['nullable', 'string', 'max:4096'], 'zip_secret' => ['nullable', 'string', 'max:4096'],
            'zip_account' => ['nullable', 'integer', 'min:1'], 'zip_origin' => ['nullable', 'integer', 'min:1'],
            'cod_enabled' => ['required', 'boolean'], 'cod_delivery_enabled' => ['required', 'boolean'],
            'arca_environment' => ['required', Rule::in(['homologation', 'production'])],
            'arca_cuit' => ['nullable', 'regex:/^\d{11}$/'], 'arca_pos' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'arca_condition' => ['required', Rule::in(['monotributo', 'ri', 'exento'])],
            'arca_name' => ['nullable', 'string', 'max:200'], 'arca_address' => ['nullable', 'string', 'max:300'],
            'arca_iibb' => ['nullable', 'string', 'max:80'], 'arca_activity_date' => ['nullable', 'date_format:Y-m-d'],
            'certificate' => ['nullable', 'file', 'max:64'], 'private_key' => ['nullable', 'file', 'max:64'],
            'production_ack' => ['nullable', 'accepted'],
        ]);
        $current = IntegrationSettings::allValues();
        foreach (['mp_token', 'mp_webhook_secret', 'zip_token', 'zip_secret'] as $key) {
            if (empty($data[$key])) {
                unset($data[$key]);
            }
        }
        foreach (['certificate', 'private_key'] as $key) {
            unset($data[$key]);
            if ($r->hasFile($key)) {
                $bytes = file_get_contents($r->file($key)->getRealPath());
                $valid = $key === 'certificate' ? openssl_x509_read($bytes) : openssl_pkey_get_private($bytes);
                if (! $valid) {
                    throw ValidationException::withMessages([$key => 'Archivo PEM inválido. La clave debe estar sin contraseña y se guarda en almacenamiento privado.']);
                }
                $path = $r->file($key)->store('arca', 'local');
                $data[$key === 'certificate' ? 'arca_certificate' : 'arca_key'] = Storage::disk('local')->path($path);
            }
        }
        $merged = array_replace($current, $data);
        if (! empty($merged['arca_certificate']) && ! empty($merged['arca_key']) && is_file($merged['arca_certificate']) && is_file($merged['arca_key']) && ! openssl_x509_check_private_key(file_get_contents($merged['arca_certificate']), file_get_contents($merged['arca_key']))) {
            throw ValidationException::withMessages(['private_key' => 'El certificado y la clave no coinciden.']);
        }
        if ($data['arca_environment'] === 'production' && ($current['arca_environment'] ?? 'homologation') !== 'production') {
            if (! $r->boolean('production_ack')) {
                throw ValidationException::withMessages(['production_ack' => 'Confirmá que probaste la emisión en homologación antes de activar producción.']);
            }
            foreach (['arca_cuit', 'arca_pos', 'arca_name', 'arca_address', 'arca_activity_date', 'arca_certificate', 'arca_key'] as $key) {
                if (empty($merged[$key])) {
                    throw ValidationException::withMessages([$key => 'Completá todos los requisitos fiscales antes de activar producción.']);
                }
            }
        }
        unset($data['production_ack']);
        IntegrationSettings::updateOrCreate(['id' => 1], ['values' => array_replace(IntegrationSettings::find(1)?->values ?? [], $data)]);

        return back()->with('success', 'Configuración guardada. Los secretos quedaron cifrados.');
    }
}
