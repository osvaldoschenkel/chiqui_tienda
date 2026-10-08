<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'document', 'address', 'notes', 'active'];

    protected function casts(): array { return ['active' => 'boolean']; }
    public function ledgerEntries(): HasMany { return $this->hasMany(LedgerEntry::class); }
    public function purchases(): HasMany { return $this->hasMany(Purchase::class); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
    public function getBalanceCentsAttribute(): int
    {
        return (int) $this->ledgerEntries()->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_cents ELSE -amount_cents END), 0) AS balance")->value('balance');
    }
}
