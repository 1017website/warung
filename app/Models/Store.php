<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'allow_custom_amount' => 'boolean',
        'receipt_show_logo' => 'boolean',
        'receipt_sort_by_category' => 'boolean',
        'non_real_percentage' => 'decimal:2',
        'member_discount_percent' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Store $store) {
            if (! $store->tenant_id) {
                return;
            }

            $tenant = Tenant::find($store->tenant_id);
            if (! $tenant) {
                return;
            }

            $defaults = [
                'business_name' => $tenant->name,
                'logo_path' => $tenant->logo_path,
                'allow_custom_amount' => $tenant->allow_custom_amount,
                'non_real_percentage' => $tenant->non_real_percentage,
                'member_discount_percent' => $tenant->member_discount_percent,
                'receipt_header' => $tenant->receipt_header,
                'receipt_footer' => $tenant->receipt_footer,
                'receipt_show_logo' => $tenant->receipt_show_logo,
                'receipt_sort_by_category' => $tenant->receipt_sort_by_category,
            ];

            foreach ($defaults as $key => $value) {
                if (! array_key_exists($key, $store->getAttributes())) {
                    $store->setAttribute($key, $value);
                }
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function brandName(): string
    {
        return $this->business_name ?: $this->tenant?->name ?: $this->name;
    }
}
