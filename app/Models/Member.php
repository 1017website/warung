<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['deposit_balance' => 'decimal:2', 'discount_percent' => 'decimal:2', 'birth_date' => 'date', 'is_active' => 'boolean'];

    public function card()
    {
        return $this->hasOne(MemberCard::class);
    }
}
