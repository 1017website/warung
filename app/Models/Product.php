<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['selling_price' => 'decimal:2', 'online_selling_price' => 'decimal:2', 'purchase_price' => 'decimal:2', 'is_active' => 'boolean'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }

    public function dailyStocks()
    {
        return $this->hasMany(DailyMenuStock::class);
    }
}
