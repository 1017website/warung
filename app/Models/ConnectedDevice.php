<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectedDevice extends Model
{
    protected $guarded = [];

    protected $casts = ['last_tested_at' => 'datetime'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
