<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierClosing extends Model
{
    protected $guarded = [];

    protected $casts = [
        'closing_date' => 'date',
        'closed_at' => 'datetime',
        'opening_cash' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'cash_topups' => 'decimal:2',
        'cash_expenses' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'difference' => 'decimal:2',
        'payment_summary' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function authorizer()
    {
        return $this->belongsTo(User::class, 'authorized_by')->withTrashed();
    }
}
