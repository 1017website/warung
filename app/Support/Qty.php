<?php

namespace App\Support;

/**
 * Kuantitas disimpan dengan tiga desimal. Tampilkan tanpa nol di belakang koma
 * supaya 2.000 (dua) tidak terbaca sebagai dua ribu dalam format Indonesia.
 */
final class Qty
{
    public static function format(float|int|string|null $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    }
}
