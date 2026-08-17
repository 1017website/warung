<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryDailyRecord extends Model
{
    protected $guarded = [];

    protected $casts = [
        'record_date' => 'date',
        'opening_quantity' => 'decimal:3',
        'used_quantity' => 'decimal:3',
        'opening_is_manual' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
