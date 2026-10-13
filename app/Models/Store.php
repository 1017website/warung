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
        'rounding_unit' => 'integer',
    ];

    public const SERVICE_TYPES = ['dine_in' => 'Dine in', 'takeaway' => 'Take away', 'online' => 'Ojek online'];

    public const ROUNDING_MODES = ['none' => 'Tanpa pembulatan', 'up' => 'Ke atas', 'down' => 'Ke bawah', 'nearest' => 'Terdekat'];

    public const ROUNDING_UNITS = [100, 500, 1000];

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
     * diskon ditambah service, seperti PB1 restoran. Hasil dibulatkan ke rupiah, lalu
     * total akhirnya mengikuti pembulatan cabang (rounding_amount, boleh negatif).
     * Rumus yang sama dipakai layar kasir (lihat totals() di pos/index.blade.php).
     */
    public function chargesFor(float $base, string $serviceType): array
    {
        $servicePercent = in_array($serviceType, $this->serviceChargeTypes(), true) ? (float) $this->service_charge_percent : 0.0;
        $taxPercent = in_array($serviceType, $this->taxTypes(), true) ? (float) $this->tax_percent : 0.0;
        $service = $base > 0 ? round($base * $servicePercent / 100) : 0.0;
        $tax = $base > 0 ? round(($base + $service) * $taxPercent / 100) : 0.0;

        $total = $base + $service + $tax;

        return [
            'service_charge_percent' => $servicePercent,
            'service_charge' => $service,
            'tax_percent' => $taxPercent,
            'tax_label' => $taxPercent > 0 ? $this->taxLabel() : null,
            'tax_amount' => $tax,
            'rounding_amount' => round($this->roundTotal($total) - $total, 2),
        ];
    }

    public function roundingMode(): string
    {
        return array_key_exists((string) $this->rounding_mode, self::ROUNDING_MODES) ? $this->rounding_mode : 'none';
    }

    public function roundingUnit(): int
    {
        return in_array((int) $this->rounding_unit, self::ROUNDING_UNITS, true) ? (int) $this->rounding_unit : 100;
    }

    /**
     * Pembulatan total tagihan ke kelipatan Rp100/500/1.000. Pembulatan ke bawah
     * tidak pernah membuat tagihan menjadi nol. Rumus yang sama dipakai totals()
     * di pos/index.blade.php.
     */
    public function roundTotal(float $total): float
    {
        $mode = $this->roundingMode();
        if ($total <= 0 || $mode === 'none') {
            return $total;
        }
        $unit = $this->roundingUnit();
        // round(..., 6) menghindari 1000.0000001 dibulatkan ke atas menjadi 2000.
        $steps = round($total / $unit, 6);
        $rounded = match ($mode) {
            'up' => ceil($steps),
            'down' => floor($steps),
            default => floor($steps + 0.5),
        } * $unit;

        return $rounded > 0 ? (float) $rounded : $total;
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
            'rounding_mode' => $this->roundingMode(),
            'rounding_unit' => $this->roundingUnit(),
        ];
    }
}
