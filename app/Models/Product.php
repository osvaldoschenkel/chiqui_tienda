<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['name', 'sku', 'barcode', 'category', 'description', 'image_path', 'supplier_id', 'cost_cents', 'price_cents', 'stock', 'min_stock', 'active'];

    protected function casts(): array
    {
        return ['cost_cents' => 'integer', 'price_cents' => 'integer', 'stock' => 'integer', 'min_stock' => 'integer', 'active' => 'boolean'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function saleItems(): HasMany { return $this->hasMany(SaleItem::class); }
    public function purchaseItems(): HasMany { return $this->hasMany(PurchaseItem::class); }
    public function stockMovements(): HasMany { return $this->hasMany(StockMovement::class); }
}
