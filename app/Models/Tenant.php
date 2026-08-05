<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'allow_custom_amount' => 'boolean',
        'receipt_show_logo' => 'boolean',
        'receipt_sort_by_category' => 'boolean',
        'non_real_percentage' => 'decimal:2',
        'member_discount_percent' => 'decimal:2',
    ];

    public function stores()
    {
        return $this->hasMany(Store::class);
    }
}
