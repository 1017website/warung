<?php

namespace App\Support;

/** Pembacaan nilai sel Excel/CSV yang dipakai seluruh impor. */
final class SheetValue
{
    /** Teks dari sel Excel; SKU/barcode yang tersimpan sebagai angka tidak berubah menjadi notasi E. */
    public static function text(mixed $value): string
    {
        if (is_float($value) || is_int($value)) {
            return floor($value) == $value ? sprintf('%.0f', $value) : (string) $value;
        }

        return trim((string) $value);
    }

    /** Angka dari sel Excel/CSV: 15000, "15.000", dan "15,000" dibaca 15000; sel kosong menjadi null. */
    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $value = str_replace(['Rp', 'rp', ' '], '', trim((string) $value));
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $value)) {
            return (float) preg_replace('/\D/', '', $value);
        }
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }
}
