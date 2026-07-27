<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function stores()
    {
        return $this->hasMany(Store::class);
    }
}
