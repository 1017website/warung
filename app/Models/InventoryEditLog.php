<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryEditLog extends Model
{
    public const FIELDS = [
        'opening_quantity' => 'Stok awal',
        'incoming_quantity' => 'Stok datang',
        'used_quantity' => 'Stok terpakai',
        'produced_quantity' => 'Tambahan olahan',
        'consumption_quantity' => 'Konsumsi',
        'reprocessed_quantity' => 'Diproses ulang',
        'waste_quantity' => 'Rusak / tidak layak',
        'notes' => 'Keterangan',
    ];

    protected $guarded = [];

    public function fieldLabel(): string
    {
        return self::FIELDS[$this->field] ?? $this->field;
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
