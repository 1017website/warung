<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['deposit_balance' => 'decimal:2', 'is_active' => 'boolean'];
}
