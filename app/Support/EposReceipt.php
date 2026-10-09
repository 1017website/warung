<?php

namespace App\Support;

use App\Models\CashierClosing;
use App\Models\Store;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
    public static function closing(Store $store, array $summary, iterable $sales, ?CashierClosing $closing, int $columns, ?Collection $transactions = null): array
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
        $lines[] = self::text('OMZET HARIAN', 'left', true);
        $lines[] = self::text(self::pair('Transaksi selesai', (string) $summary['transactions'], $columns));
        array_push($lines, ...self::turnoverLines($summary, $columns));
        $lines[] = self::rule($columns);
        $lines[] = self::text('KAS TUNAI', 'left', true);
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
        if ($transactions !== null) {
            $lines[] = self::rule($columns);
            $lines[] = self::text('DETAIL TRANSAKSI', 'left', true);
            $completedTotal = 0.0;
            foreach ($transactions as $transaction) {
                $lines[] = self::rule($columns);
                $lines[] = self::text(self::pair($transaction->invoice_no, $transaction->transacted_at->format('H:i'), $columns), 'left', true);
                $lines[] = self::text(self::pair(self::serviceLabel($transaction), $transaction->user?->name ?? '-', $columns));
                if ($transaction->status !== 'completed' || $transaction->transaction_type === 'replacement') {
                    $lines[] = self::text('** '.self::statusLabel($transaction).' **');
                }
                foreach ($transaction->items as $item) {
                    $name = self::wrap(Qty::format($item->quantity).' x '.$item->product_name, max(8, $columns - strlen(self::money($item->subtotal)) - 1));
                    $lines[] = self::text(self::pair('  '.array_shift($name), self::money($item->subtotal), $columns));
                    foreach ($name as $rest) {
                        $lines[] = self::text('  '.$rest);
                    }
                }
                foreach ($transaction->payments as $payment) {
                    $lines[] = self::text(self::pair('  '.Str::upper($payment->method).($payment->provider ? ' '.$payment->provider : ''), self::money($payment->amount), $columns));
                }
                $lines[] = self::text(self::pair('Total', 'Rp '.self::money($transaction->total), $columns), 'left', true);
                if ($transaction->status === 'completed' && $transaction->transaction_type === 'sale') {
                    $completedTotal += (float) $transaction->total;
                }
            }
            if ($transactions->isEmpty()) {
                $lines[] = self::text('Belum ada transaksi.');
            }
            $lines[] = self::rule($columns);
            $lines[] = self::text(self::pair('TOTAL PENJUALAN', 'Rp '.self::money($completedTotal), $columns), 'left', true);
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

    /** Laporan produk terjual untuk rentang tanggal, per kategori, untuk printer struk. */
    public static function productSales(string $brand, string $scope, Carbon $from, Carbon $to, array $data, int $columns, bool $nonReal = false): array
    {
        $lines = [
            self::text('PRODUK TERJUAL', 'center', true, true),
            self::text($brand, 'center', true),
            self::text($scope, 'center'),
        ];
        if ($nonReal) {
            $lines[] = self::text('Laporan non-riil', 'center');
        }
        $lines[] = self::rule($columns);
        $lines[] = self::text(self::pair('Periode', $from->isSameDay($to) ? $from->format('d/m/Y') : $from->format('d/m/Y').' - '.$to->format('d/m/Y'), $columns));
        $lines[] = self::text(self::pair('Dicetak', now()->format('d/m/Y H:i'), $columns));
        foreach ($data['categories'] as $category => $items) {
            $lines[] = self::rule($columns);
            $lines[] = self::text(Str::upper($category), 'left', true);
            foreach ($items as $item) {
                foreach (self::wrap($item->product_name, $columns) as $nameLine) {
                    $lines[] = self::text($nameLine);
                }
                $lines[] = self::text(self::pair('  '.Qty::format($item->quantity).' '.$item->unit, self::money($item->sales), $columns));
            }
            $lines[] = self::text(self::pair('  Subtotal '.Qty::format($items->sum('quantity')).' item', self::money($items->sum('sales')), $columns), 'left', true);
        }
        if ($data['products']->isEmpty()) {
            $lines[] = self::rule($columns);
            $lines[] = self::text('Belum ada produk terjual.');
        }
        $totals = $data['totals'];
        $lines[] = self::rule($columns);
        $lines[] = self::text(self::pair('Transaksi selesai', (string) $totals['transactions'], $columns));
        $lines[] = self::text(self::pair('Total qty', Qty::format($totals['quantity']), $columns));
        array_push($lines, ...self::turnoverLines($totals, $columns));
        $lines[] = ['type' => 'feed', 'lines' => 3];
        $lines[] = ['type' => 'cut'];

        return $lines;
    }

    /** Penjualan produk − diskon + service + pajak = omzet. */
    private static function turnoverLines(array $totals, int $columns): array
    {
        $lines = [self::text(self::pair('Penjualan produk', 'Rp '.self::money($totals['grossSales']), $columns))];
        foreach (['discount' => 'Diskon', 'serviceCharge' => 'Service', 'tax' => 'Pajak'] as $key => $label) {
            if ((float) $totals[$key] > 0) {
                $lines[] = self::text(self::pair($label, ($key === 'discount' ? '-Rp ' : 'Rp ').self::money($totals[$key]), $columns));
            }
        }
        $lines[] = self::text(self::pair('OMZET', 'Rp '.self::money($totals['turnover']), $columns), 'left', true);

        return $lines;
    }

    private static function groups(Transaction $transaction)
    {
        return $transaction->items->groupBy(fn ($item) => trim($item->category_name ?? '') ?: 'Umum')->sortKeys();
    }

    public static function serviceLabel(Transaction $transaction): string
    {
        return match ($transaction->service_type) {
            'takeaway' => 'Take Away',
            'online' => 'Ojek Online'.($transaction->online_platform ? ' - '.$transaction->online_platform : ''),
            default => 'Dine In - Meja '.($transaction->table_number ?: '-'),
        };
    }

    public static function statusLabel(Transaction $transaction): string
    {
        return match ($transaction->status) {
            'voided' => 'DIBATALKAN',
            'pending' => 'OPEN BILL',
            default => $transaction->transaction_type === 'replacement' ? 'RETUR / PENGGANTI' : 'SELESAI',
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
