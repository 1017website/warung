<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pilihan harga tambahan untuk satu SKU, misalnya porsi besar atau paket.
 * store_id kosong berarti berlaku di semua warung.
 */
class ProductPrice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
        'online_price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function appliesTo(?int $storeId): bool
    {
        return $this->store_id === null || (int) $this->store_id === (int) $storeId;
    }

    public function priceFor(bool $online): float
    {
        return $online && (float) $this->online_price > 0 ? (float) $this->online_price : (float) $this->price;
    }
}
