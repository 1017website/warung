<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['expense_date' => 'date', 'amount' => 'decimal:2'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
