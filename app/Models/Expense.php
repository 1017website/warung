<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    public const CATEGORIES = [
        'S&W',
        'F&B',
        'Snack (F)',
        'Snack (B)',
        'Talent',
        'Marketing',
        "Owner's Drawings",
        'Waste',
        'Others',
    ];

    protected $guarded = [];

    protected $casts = ['expense_date' => 'date', 'amount' => 'decimal:2'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
