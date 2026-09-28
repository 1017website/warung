<?php

namespace App\Support;

use Closure;

/**
 * Ikon menu dapat berupa Bootstrap Icons dari daftar ini atau satu emoji.
 * Emoji dipakai untuk makanan yang tidak tersedia di Bootstrap Icons.
 */
final class MenuIcon
{
    public const OPTIONS = [
        'bi-egg-fried' => 'Gorengan / telur',
        'bi-fire' => 'Bakar / pedas',
        'bi-droplet' => 'Kuah / sup',
        'bi-basket' => 'Nasi / paket',
        'bi-flower1' => 'Sayur',
        'bi-apple' => 'Buah',
        'bi-cake2' => 'Kue',
        'bi-cookie' => 'Snack',
        'bi-cup-hot' => 'Minuman panas',
        'bi-cup-straw' => 'Minuman dingin',
        'bi-snow' => 'Es',
        'bi-box2-heart' => 'Paket hemat',
        'bi-bag' => 'Bungkus',
        'bi-gift' => 'Promo',
        'bi-star' => 'Favorit',
        'bi-tag' => 'Umum',
        'bi-box-seam' => 'Bahan baku',
    ];

    public const EMOJI = ['🍚', '🍗', '🍖', '🥩', '🐟', '🍤', '🥚', '🍳', '🍜', '🍲', '🥘', '🍛', '🥗', '🥦', '🌶️', '🍢', '🥟', '🍞', '🍰', '🍩', '🍪', '🍌', '🍉', '☕', '🍵', '🧃', '🥤', '🧋', '🍹', '🧊', '💧', '🍱', '🥡', '⭐'];

    public static function fallback(?string $productType): string
    {
        return $productType === 'ingredient' ? 'bi-box-seam' : 'bi-egg-fried';
    }

    public static function isBootstrap(?string $icon): bool
    {
        return is_string($icon) && str_starts_with($icon, 'bi-');
    }

    /** Aturan validasi: ikon Bootstrap dari daftar, atau emoji pendek tanpa huruf/angka ASCII. */
    public static function rules(): array
    {
        return ['bail', 'nullable', 'string', 'max:40', function (string $attribute, mixed $value, Closure $fail) {
            if (self::isBootstrap($value)) {
                if (! array_key_exists($value, self::OPTIONS)) {
                    $fail('Ikon yang dipilih tidak tersedia.');
                }

                return;
            }
            if (preg_match('/[\x00-\x7F]/', (string) $value) || mb_strlen((string) $value) > 8) {
                $fail('Ikon harus dipilih dari daftar atau berupa satu emoji.');
            }
        }];
    }
}
