<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LedgerEntry extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['amount_cents' => 'integer', 'date' => 'datetime']; }
    protected static function booted(): void
    {
        static::creating(function (LedgerEntry $entry) {
            if ((bool) $entry->customer_id === (bool) $entry->supplier_id || $entry->amount_cents <= 0 || ! in_array($entry->direction, ['debit', 'credit'], true)) {
                throw new LogicException('Un asiento requiere exactamente un titular, una dirección y un importe positivo.');
            }
        });
        static::updating(fn () => throw new LogicException('Los movimientos de cuenta corriente son inmutables. Registrá un contramovimiento.'));
        static::deleting(fn () => throw new LogicException('Los movimientos de cuenta corriente son inmutables. Registrá un contramovimiento.'));
    }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
