<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalInvoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'cae_expires_at' => 'date'];
    }

    public function order()
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }
}
