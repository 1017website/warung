<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockProduction extends Model
{
    protected $guarded = [];

    protected $casts = ['production_date' => 'date', 'ingredient_quantity' => 'decimal:3', 'output_quantity' => 'decimal:3'];

    /** Riwayat produksi tetap menampilkan produk yang sudah diarsipkan. */
    public function ingredient()
    {
        return $this->belongsTo(Product::class, 'ingredient_product_id')->withTrashed();
    }

    public function menu()
    {
        return $this->belongsTo(Product::class, 'menu_product_id')->withTrashed();
    }
}
