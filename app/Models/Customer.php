<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'document', 'address', 'notes', 'credit_limit_cents', 'active'];

    protected function casts(): array
    {
        return ['credit_limit_cents' => 'integer', 'active' => 'boolean'];
    }

    public function ledgerEntries(): HasMany { return $this->hasMany(LedgerEntry::class); }
    public function sales(): HasMany { return $this->hasMany(Sale::class); }
    public function getBalanceCentsAttribute(): int
    {
        return (int) $this->ledgerEntries()->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_cents ELSE -amount_cents END), 0) AS balance")->value('balance');
    }
}
