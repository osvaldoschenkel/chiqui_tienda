<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class ShippingCart
{
    public static function items(array $cart): array
    {
        $products = Product::whereIn('id', array_keys($cart))->get()->keyBy('id');
        $items = [];
        foreach ($cart as $id => $quantity) {
            $p = $products->get($id);
            if (! $p || ! $p->active || $p->stock < $quantity) {
                throw ValidationException::withMessages(['cart' => 'Un producto o cantidad no está disponible.']);
            }
            $items[] = ['product_id' => $p->id, 'name' => $p->name, 'quantity' => $quantity, 'unit_price_cents' => $p->price_cents, 'weight_grams' => $p->weight_grams, 'height_cm' => (float) $p->height_cm, 'width_cm' => (float) $p->width_cm, 'length_cm' => (float) $p->length_cm];
        }

        return $items;
    }

    public static function hash(array $items, array $address): string
    {
        usort($items, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
        $lines = array_map(fn ($i) => array_intersect_key($i, array_flip(['product_id', 'quantity', 'unit_price_cents', 'weight_grams', 'height_cm', 'width_cm', 'length_cm'])), $items);
        foreach ($lines as &$line) {
            ksort($line);
        }unset($line);

        return hash('sha256', json_encode([$lines, $address['postcode'] ?? '', $address['city'] ?? '', $address['state'] ?? ''], JSON_THROW_ON_ERROR));
    }
}
