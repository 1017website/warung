<?php

namespace App\Models;

use App\Support\MenuIcon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['selling_price' => 'decimal:2', 'online_selling_price' => 'decimal:2', 'purchase_price' => 'decimal:2', 'is_active' => 'boolean'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }

    public function dailyStocks()
    {
        return $this->hasMany(DailyMenuStock::class);
    }

    public function storePrices()
    {
        return $this->hasMany(ProductStorePrice::class);
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class)->orderBy('position')->orderBy('id');
    }

    /** Ikon produk, lalu ikon kategori, lalu ikon bawaan sesuai jenis produk. */
    public function displayIcon(): string
    {
        return $this->icon ?: ($this->category?->icon ?: MenuIcon::fallback($this->product_type));
    }

    public function storePriceFor(?int $storeId): ?ProductStorePrice
    {
        if ($storeId === null) {
            return null;
        }

        return $this->relationLoaded('storePrices')
            ? $this->storePrices->firstWhere('store_id', $storeId)
            : $this->storePrices()->where('store_id', $storeId)->first();
    }

    public function isAvailableAt(?int $storeId): bool
    {
        return $this->storePriceFor($storeId)?->is_available ?? true;
    }

    /**
     * Harga normal: harga khusus warung bila diisi, selain itu harga default.
     * Harga online: harga online khusus warung, lalu harga normal khusus warung,
     * lalu harga online default, lalu harga normal default.
     */
    public function priceAt(?int $storeId, bool $online = false): float
    {
        $override = $this->storePriceFor($storeId);
        $normal = $override?->selling_price !== null ? (float) $override->selling_price : (float) $this->selling_price;
        if (! $online) {
            return $normal;
        }
        if ($override?->online_selling_price !== null && (float) $override->online_selling_price > 0) {
            return (float) $override->online_selling_price;
        }
        if ($override?->selling_price !== null) {
            return $normal;
        }

        return (float) $this->online_selling_price > 0 ? (float) $this->online_selling_price : $normal;
    }

    public function hasStorePrice(?int $storeId): bool
    {
        $override = $this->storePriceFor($storeId);

        return $override !== null && ($override->selling_price !== null || $override->online_selling_price !== null);
    }

    /** Pilihan harga tambahan yang berlaku di warung tertentu. */
    public function pricesAt(?int $storeId)
    {
        $prices = $this->relationLoaded('prices') ? $this->prices : $this->prices()->get();

        return $prices->filter(fn (ProductPrice $price) => $price->appliesTo($storeId))->values();
    }
}
