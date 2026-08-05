<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['purchased_at' => 'date', 'received_at' => 'datetime', 'dp_amount' => 'decimal:2'];

    public function items()
    {
        return $this->hasMany(PurchaseItem::class)->with('product');
    }
}
