<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class IntegrationSettings extends Model
{
    protected $table = 'integration_settings';

    protected $fillable = ['values'];

    protected function casts(): array
    {
        return ['values' => 'encrypted:array'];
    }

    public static function allValues(): array
    {
        return array_replace(config('commerce'), static::find(1)?->values ?? []);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::allValues()[$key] ?? $default;
    }

    public static function ready(string $provider): bool
    {
        $keys = match ($provider) {
            'mp' => ['mp_token', 'mp_webhook_secret'],'zip' => ['zip_token', 'zip_secret', 'zip_account', 'zip_origin'],'arca' => ['arca_cuit', 'arca_pos', 'arca_certificate', 'arca_key', 'arca_name', 'arca_address', 'arca_activity_date'],default => []
        };

        return $keys !== [] && ! in_array(false, array_map(fn ($k) => filled(static::get($k)), $keys), true);
    }
}
