<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionItem extends Model
{
    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:3'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
