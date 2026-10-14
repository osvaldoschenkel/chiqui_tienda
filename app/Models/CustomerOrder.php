<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOrder extends Model
{
    public const STATUSES = [
        'pending' => 'Pendiente',
        'preparing' => 'Preparando',
        'shipped' => 'Listo para retirar',
        'delivered' => 'Entregado',
        'cancelled' => 'Cancelado',
    ];

    protected $fillable = [
        'user_id', 'number', 'request_id', 'request_hash', 'items', 'total_cents',
        'status', 'payment_status', 'delivery_method', 'address', 'notes',
        'tracking_code', 'tracking_url', 'payment_confirmed_at', 'payment_confirmed_by',
        'cancelled_at', 'cancelled_by', 'stock_released_at',
        'payment_method', 'payment_id', 'payment_url', 'preference_id', 'shipping_cents', 'shipping_selection', 'shipment_id', 'shipment_state', 'billing', 'reservation_expires_at', 'sale_id', 'payment_review',
    ];

    protected function casts(): array
    {
        return [
            'billing' => 'array', 'shipping_selection' => 'array', 'shipping_cents' => 'integer', 'reservation_expires_at' => 'datetime',
            'user_id' => 'integer', 'total_cents' => 'integer', 'items' => 'array', 'address' => 'array',
            'payment_confirmed_at' => 'datetime', 'cancelled_at' => 'datetime', 'stock_released_at' => 'datetime',
        ];
    }

    public function invoice()
    {
        return $this->hasOne(FiscalInvoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_confirmed_by');
    }
}
