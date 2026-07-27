<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyMenuStock extends Model
{
    protected $guarded = [];

    protected $casts = ['stock_date' => 'date'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
