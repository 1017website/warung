<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionPayment extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2'];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
