<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyMenuStock extends Model
{
    protected $guarded = [];

    protected $casts = ['stock_date' => 'date', 'quantity' => 'decimal:3'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
