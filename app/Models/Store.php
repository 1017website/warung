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
        'service_charge_percent' => 'decimal:2',
        'service_charge_types' => 'array',
        'tax_percent' => 'decimal:2',
        'tax_types' => 'array',
    ];

    public const SERVICE_TYPES = ['dine_in' => 'Dine in', 'takeaway' => 'Take away', 'online' => 'Ojek online'];

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

    /** Service charge umumnya hanya untuk makan di tempat. */
    public function serviceChargeTypes(): array
    {
        return $this->service_charge_types ?? ['dine_in'];
    }

    public function taxTypes(): array
    {
        return $this->tax_types ?? array_keys(self::SERVICE_TYPES);
    }

    public function taxLabel(): string
    {
        return $this->tax_label ?: 'Pajak';
    }

    /**
     * Service dihitung dari total setelah diskon; pajak dihitung dari total setelah
     * diskon ditambah service, seperti PB1 restoran. Hasil dibulatkan ke rupiah.
     * Rumus yang sama dipakai layar kasir (lihat totals() di pos/index.blade.php).
     */
    public function chargesFor(float $base, string $serviceType): array
    {
        $servicePercent = in_array($serviceType, $this->serviceChargeTypes(), true) ? (float) $this->service_charge_percent : 0.0;
        $taxPercent = in_array($serviceType, $this->taxTypes(), true) ? (float) $this->tax_percent : 0.0;
        $service = $base > 0 ? round($base * $servicePercent / 100) : 0.0;
        $tax = $base > 0 ? round(($base + $service) * $taxPercent / 100) : 0.0;

        return [
            'service_charge_percent' => $servicePercent,
            'service_charge' => $service,
            'tax_percent' => $taxPercent,
            'tax_label' => $taxPercent > 0 ? $this->taxLabel() : null,
            'tax_amount' => $tax,
        ];
    }

    /** Konfigurasi pajak & service untuk perhitungan di layar kasir. */
    public function chargeConfig(): array
    {
        return [
            'service_percent' => (float) $this->service_charge_percent,
            'service_types' => $this->serviceChargeTypes(),
            'tax_percent' => (float) $this->tax_percent,
            'tax_types' => $this->taxTypes(),
            'tax_label' => $this->taxLabel(),
        ];
    }
}
