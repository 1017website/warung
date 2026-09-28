<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Harga khusus satu warung; kolom kosong berarti mengikuti harga default produk. */
class ProductStorePrice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'online_selling_price' => 'decimal:2',
        'is_available' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
