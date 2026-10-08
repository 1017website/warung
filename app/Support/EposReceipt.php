<?php

namespace App\Support;

use App\Models\CashierClosing;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Support\Str;

/**
 * Menyusun struk sebagai daftar perintah sederhana (text/feed/cut/pulse). Perintah
 * diubah menjadi ePOS-Print XML oleh public/js/epos-printer.js lalu dikirim browser
 * ke printer Epson. Teks dibuat ASCII karena mode teks printer tidak memuat emoji
 * maupun tanda seperti ×.
 */
final class EposReceipt
{
    public static function jobs(Transaction $transaction, Store $store, int $columns, bool $kitchenCopy, bool $openDrawer): array
    {
        $jobs = [self::customer($transaction, $store, $columns, $openDrawer)];
        if ($kitchenCopy) {
            $jobs[] = self::kitchen($transaction, $columns);
        }

        return $jobs;
    }

    public static function customer(Transaction $transaction, Store $store, int $columns, bool $openDrawer = false): array
    {
        $lines = [];
        if ($openDrawer && $transaction->payments->contains('method', 'cash')) {
            $lines[] = ['type' => 'pulse'];
        }
        $lines[] = self::text($store->brandName(), 'center', true, true);
        foreach (array_filter([$store->receipt_header, $transaction->store?->name, $transaction->store?->address, $transaction->store?->phone]) as $line) {
            $lines[] = self::text($line, 'center');
        }
        if ($transaction->transaction_type === 'replacement') {
            $lines[] = self::text('RETUR / TRANSAKSI PENGGANTI', 'center', true);
        }
        $lines[] = self::rule($columns);
        foreach ([
            ['No.', $transaction->invoice_no],
            ['Waktu', $transaction->transacted_at->format('d/m/Y H:i')],
            ['Kasir', $transaction->user?->name ?? '-'],
            ['Pesanan', self::serviceLabel($transaction)],
        ] as [$label, $value]) {
            $lines[] = self::text(self::pair($label, $value, $columns));
        }
        if ($transaction->member) {
            $lines[] = self::text(self::pair('Member', $transaction->member->member_code, $columns));
        }
        $lines[] = self::rule($columns);

        foreach (self::groups($transaction) as $category => $items) {
            $lines[] = self::text(Str::upper($category), 'left', true);
            foreach ($items as $item) {
                foreach (self::wrap($item->product_name.($item->is_custom ? ' *' : ''), $columns) as $nameLine) {
                    $lines[] = self::text($nameLine);
                }
                $lines[] = self::text(self::pair('  '.Qty::format($item->quantity).' x '.self::money($item->price), self::money($item->subtotal), $columns));
            }
        }

        $lines[] = self::rule($columns);
        $lines[] = self::text(self::pair('Subtotal', 'Rp '.self::money($transaction->subtotal), $columns));
        if ((float) $transaction->discount > 0) {
            $label = in_array($transaction->discount_type, ['percent', 'member'], true) ? 'Diskon ('.Qty::format($transaction->discount_value).'%)' : 'Diskon';
            $lines[] = self::text(self::pair($label, '-Rp '.self::money($transaction->discount), $columns));
        }
        if ((float) $transaction->service_charge > 0) {
            $lines[] = self::text(self::pair('Service ('.Qty::format($transaction->service_charge_percent).'%)', 'Rp '.self::money($transaction->service_charge), $columns));
        }
        if ((float) $transaction->tax_amount > 0) {
            $lines[] = self::text(self::pair(($transaction->tax_label ?: 'Pajak').' ('.Qty::format($transaction->tax_percent).'%)', 'Rp '.self::money($transaction->tax_amount), $columns));
        }
        $lines[] = self::text(self::pair('TOTAL', 'Rp '.self::money($transaction->total), $columns), 'left', true);
        foreach ($transaction->payments as $payment) {
            $lines[] = self::text(self::pair(Str::upper($payment->method).($payment->provider ? ' '.$payment->provider : ''), 'Rp '.self::money($payment->amount), $columns));
        }
        if ($transaction->payments->isEmpty()) {
            $lines[] = self::text(self::pair(Str::upper($transaction->payment_method), 'Rp '.self::money($transaction->paid_amount), $columns));
        }
        if ((float) $transaction->change_amount > 0) {
            $lines[] = self::text(self::pair('Kembali', 'Rp '.self::money($transaction->change_amount), $columns));
        }
        $lines[] = self::rule($columns);
        foreach (self::wrap($store->receipt_footer ?: 'Terima kasih sudah berbelanja.', $columns) as $footer) {
            $lines[] = self::text($footer, 'center');
        }
        $lines[] = ['type' => 'feed', 'lines' => 3];
        $lines[] = ['type' => 'cut'];

        return $lines;
    }

    public static function kitchen(Transaction $transaction, int $columns): array
    {
        $lines = [
            self::text('DAPUR', 'center', true, true),
            self::text(self::pair($transaction->invoice_no, $transaction->transacted_at->format('H:i'), $columns)),
            self::text(self::serviceLabel($transaction), 'left', true),
            self::rule($columns),
        ];
        foreach (self::groups($transaction) as $category => $items) {
            $lines[] = self::text(Str::upper($category), 'left', true);
            foreach ($items as $item) {
                // Teks dobel tinggi tetap satu lebar kolom, sehingga lebarnya tidak berubah.
                foreach (self::wrap(Qty::format($item->quantity).' x '.$item->product_name.($item->is_custom ? ' *' : ''), $columns) as $line) {
                    $lines[] = ['type' => 'text', 'text' => $line, 'align' => 'left', 'bold' => true, 'double' => false, 'tall' => true];
                }
            }
        }
        if ($transaction->notes) {
            $lines[] = self::rule($columns);
            foreach (self::wrap('Catatan: '.$transaction->notes, $columns) as $line) {
                $lines[] = self::text($line);
            }
        }
        $lines[] = ['type' => 'feed', 'lines' => 3];
        $lines[] = ['type' => 'cut'];

        return $lines;
    }

    /** Rekap tutup kasir harian untuk printer struk (RawBT/Epson). */
    public static function closing(Store $store, array $summary, iterable $sales, ?CashierClosing $closing, int $columns): array
    {
        $lines = [
            self::text('REKAP TUTUP KASIR', 'center', true, true),
            self::text($store->brandName(), 'center', true),
            self::text($store->name, 'center'),
            self::rule($columns),
            self::text(self::pair('Tanggal', ($closing?->closing_date ?? today())->format('d/m/Y'), $columns)),
            self::text(self::pair('Dicetak', now()->format('d/m/Y H:i'), $columns)),
        ];
        if ($closing) {
            $lines[] = self::text(self::pair('Ditutup', $closing->closed_at?->format('H:i') ?? '-', $columns));
            $lines[] = self::text(self::pair('Kasir', $closing->user?->name ?? '-', $columns));
            $lines[] = self::text(self::pair('Otorisasi', $closing->authorizer?->name ?? '-', $columns));
        }
        $lines[] = self::rule($columns);
        $lines[] = self::text(self::pair('Transaksi selesai', (string) $summary['transactions'], $columns));
        foreach ([
            'Tunai penjualan' => $summary['cashSales'],
            'Top up tunai' => $summary['cashTopups'],
            'DP reservasi tunai' => $summary['cashReservationDp'],
            'Pengeluaran tunai' => -$summary['cashExpenses'],
        ] as $label => $value) {
            $lines[] = self::text(self::pair($label, ($value < 0 ? '-Rp ' : 'Rp ').self::money(abs($value)), $columns));
        }
        $lines[] = self::rule($columns);
        if ($closing) {
            $difference = (float) $closing->difference;
            $lines[] = self::text(self::pair('Modal awal', 'Rp '.self::money($closing->opening_cash), $columns));
            $lines[] = self::text(self::pair('Kas seharusnya', 'Rp '.self::money($closing->expected_cash), $columns));
            $lines[] = self::text(self::pair('Kas fisik', 'Rp '.self::money($closing->actual_cash), $columns));
            $lines[] = self::text(self::pair('SELISIH', ($difference < 0 ? '-Rp ' : 'Rp ').self::money(abs($difference)), $columns), 'left', true);
        } else {
            $lines[] = self::text('BELUM DISIMPAN', 'center', true);
            $lines[] = self::text('Kas fisik belum direkonsiliasi.', 'center');
        }
        $lines[] = self::rule($columns);
        $lines[] = self::text('RINCIAN PEMBAYARAN', 'left', true);
        foreach ($summary['paymentSummary'] as $payment) {
            $lines[] = self::text(self::pair(Str::upper($payment['method']).($payment['provider'] ? ' '.$payment['provider'] : ''), 'Rp '.self::money($payment['total']), $columns));
        }
        if (! count($summary['paymentSummary'])) {
            $lines[] = self::text('Belum ada pembayaran.');
        }
        $lines[] = self::rule($columns);
        $lines[] = self::text('PRODUK TERJUAL', 'left', true);
        $sold = 0;
        foreach ($sales as $item) {
            $sold++;
            $quantity = Qty::format($item->quantity).' '.$item->unit;
            $name = self::wrap($item->product_name, max(8, $columns - strlen(self::ascii($quantity)) - 1));
            $lines[] = self::text(self::pair(array_shift($name), $quantity, $columns));
            foreach ($name as $rest) {
                $lines[] = self::text($rest);
            }
        }
        if (! $sold) {
            $lines[] = self::text('Belum ada produk terjual.');
        }
        if ($closing?->notes) {
            $lines[] = self::rule($columns);
            foreach (self::wrap('Catatan: '.$closing->notes, $columns) as $line) {
                $lines[] = self::text($line);
            }
        }
        $lines[] = ['type' => 'feed', 'lines' => 2];
        $half = intdiv($columns, 2);
        $lines[] = self::text(str_pad('Kasir', $half).'Manager/SPV');
        $lines[] = ['type' => 'feed', 'lines' => 3];
        $lines[] = self::text(str_pad(str_repeat('_', $half - 2), $half).str_repeat('_', $columns - $half));
        $lines[] = ['type' => 'feed', 'lines' => 3];
        $lines[] = ['type' => 'cut'];

        return $lines;
    }

    private static function groups(Transaction $transaction)
    {
        return $transaction->items->groupBy(fn ($item) => trim($item->category_name ?? '') ?: 'Umum')->sortKeys();
    }

    private static function serviceLabel(Transaction $transaction): string
    {
        return match ($transaction->service_type) {
            'takeaway' => 'Take Away',
            'online' => 'Ojek Online'.($transaction->online_platform ? ' - '.$transaction->online_platform : ''),
            default => 'Dine In - Meja '.($transaction->table_number ?: '-'),
        };
    }

    private static function text(string $text, string $align = 'left', bool $bold = false, bool $double = false): array
    {
        return ['type' => 'text', 'text' => self::ascii($text), 'align' => $align, 'bold' => $bold, 'double' => $double, 'tall' => false];
    }

    private static function rule(int $columns): array
    {
        return self::text(str_repeat('-', $columns));
    }

    /** Label di kiri, nilai rata kanan; label dipotong bila baris tidak cukup. */
    public static function pair(string $left, string $right, int $columns): string
    {
        $left = self::ascii($left);
        $right = self::ascii($right);
        $space = $columns - strlen($right) - 1;
        if ($space < 1) {
            return substr($left.' '.$right, 0, $columns);
        }
        $left = strlen($left) > $space ? substr($left, 0, $space) : $left;

        return $left.str_repeat(' ', $columns - strlen($left) - strlen($right)).$right;
    }

    /** @return list<string> */
    public static function wrap(string $text, int $columns): array
    {
        return explode("\n", wordwrap(self::ascii($text), $columns, "\n", true));
    }

    private static function money(float|int|string|null $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    private static function ascii(string $text): string
    {
        // Tanpa trim: spasi di depan menjadi indentasi rincian item.
        return preg_replace('/[^\x20-\x7E]/', '', Str::ascii(str_replace(['×', '–', '—', '·'], ['x', '-', '-', '-'], $text))) ?? '';
    }
}
