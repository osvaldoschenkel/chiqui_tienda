<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class StoreSetting extends Model
{
    public const SINGLETON_ID = 1;

    public $incrementing = false;

    protected $fillable = ['name', 'tagline', 'color', 'logo_path'];

    /**
     * Read-only presentation; opening a page never creates store settings.
     * Missing tables during installation use defaults. Connection failures remain visible.
     */
    public static function presentation(): array
    {
        $defaults = config('store.defaults');
        $setting = Schema::hasTable('store_settings') ? static::find(self::SINGLETON_ID) : null;
        $name = $setting ? trim($setting->name) : $defaults['name'];
        $name = $name !== '' ? $name : $defaults['name'];
        $color = $setting?->color ?? $defaults['color'];
        if (! array_key_exists($color, config('store.colors'))) {
            $color = $defaults['color'];
        }

        return [
            'name' => $name,
            'tagline' => $setting ? (string) $setting->tagline : $defaults['tagline'],
            'color' => $color,
            'logo_url' => $setting?->logo_path ? Storage::disk('public')->url($setting->logo_path) : null,
            'initials' => static::initials($name),
        ];
    }

    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if (! $words) {
            return 'CT';
        }
        $initials = mb_substr($words[0], 0, 1);
        if (count($words) > 1) {
            $initials .= mb_substr($words[count($words) - 1], 0, 1);
        }

        return mb_strtoupper($initials);
    }
}
