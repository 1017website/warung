<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    protected $guarded = [];

    protected $casts = ['count_date' => 'date', 'expected_quantity' => 'decimal:3', 'actual_quantity' => 'decimal:3'];
}
