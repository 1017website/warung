<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAssetLog extends Model
{
    protected $guarded = [];

    public function asset()
    {
        return $this->belongsTo(InventoryAsset::class, 'inventory_asset_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
