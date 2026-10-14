<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingQuote extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['destination' => 'array', 'options' => 'array', 'expires_at' => 'datetime'];
    }
}
