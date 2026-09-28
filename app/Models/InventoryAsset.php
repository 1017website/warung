<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Peralatan warung yang tidak habis dipakai, misalnya panci dan kompor. */
class InventoryAsset extends Model
{
    use SoftDeletes;

    public const CATEGORIES = [
        'Peralatan masak',
        'Peralatan makan & minum',
        'Elektronik',
        'Furnitur',
        'Kebersihan',
        'Lainnya',
    ];

    protected $guarded = [];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_price' => 'decimal:2',
        'quantity_good' => 'integer',
        'quantity_damaged' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function logs()
    {
        return $this->hasMany(InventoryAssetLog::class)->latest();
    }

    public function totalQuantity(): int
    {
        return $this->quantity_good + $this->quantity_damaged;
    }

    public function totalValue(): float
    {
        return $this->totalQuantity() * (float) $this->purchase_price;
    }
}
