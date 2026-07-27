<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionReportExporter
{
    public function data(int $tenantId, int $storeId, Carbon $from, Carbon $to, float $factor): array
    {
        $transactions = Transaction::with(['items', 'user', 'member'])
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereBetween('transacted_at', [$from, $to])
            ->orderBy('transacted_at')
            ->get();
        $expenseRows = Expense::with('user')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('expense_date')
            ->get();

        $realSales = $transactions->sum('total');
        $realCost = $transactions->sum(fn (Transaction $transaction) => $transaction->items->sum(fn ($item) => $item->cost * $item->quantity));
        $realExpenses = $expenseRows->sum('amount');

        $daily = $transactions->groupBy(fn (Transaction $transaction) => $transaction->transacted_at->toDateString())
            ->map(fn (Collection $rows, string $date) => (object) [
                'date' => $date,
                'total' => round($rows->sum('total') * $factor, 2),
            ])->values();
        $payments = $transactions->groupBy('payment_method')
            ->map(fn (Collection $rows, string $method) => (object) [
                'payment_method' => $method,
                'total' => round($rows->sum('total') * $factor, 2),
                'count' => $rows->count(),
            ])->values();

        return [
            'transactions' => $transactions,
            'expenseRows' => $expenseRows,
            'sales' => round($realSales * $factor, 2),
            'cost' => round($realCost * $factor, 2),
            'expenses' => round($realExpenses * $factor, 2),
            'profit' => round(($realSales - $realCost - $realExpenses) * $factor, 2),
            'daily' => $daily,
            'payments' => $payments,
        ];
    }

    public function workbook(array $data, Tenant $tenant, Store $store, Carbon $from, Carbon $to, string $type, float $factor): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator($tenant->name)
            ->setTitle('Laporan transaksi '.$store->name)
            ->setSubject('Laporan '.($type === 'non_real' ? 'non-riil' : 'riil'))
            ->setDescription('Laporan transaksi dan pengeluaran WarungKita');

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Ringkasan');
        $transactionsSheet = $spreadsheet->createSheet()->setTitle('Transaksi');
        $expensesSheet = $spreadsheet->createSheet()->setTitle('Pengeluaran');

        $this->buildTransactionsSheet($transactionsSheet, $data['transactions'], $tenant, $store, $from, $to, $type, $factor);
        $this->buildExpensesSheet($expensesSheet, $data['expenseRows'], $tenant, $store, $from, $to, $type, $factor);
        $this->buildSummarySheet($summary, $tenant, $store, $from, $to, $type, count($data['transactions']), count($data['expenseRows']));

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildSummarySheet(Worksheet $sheet, Tenant $tenant, Store $store, Carbon $from, Carbon $to, string $type, int $transactionCount, int $expenseCount): void
    {
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:D1')->setCellValue('A1', 'LAPORAN TRANSAKSI');
        $sheet->mergeCells('A2:D2')->setCellValue('A2', $tenant->name.' · '.$store->name);
        $sheet->mergeCells('A3:D3')->setCellValue('A3', $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y').' · '.($type === 'non_real' ? 'Non-riil (50%)' : 'Riil'));

        $sheet->fromArray([
            ['Indikator', 'Nilai', 'Keterangan'],
            ['Omzet', "=SUM('Transaksi'!K5:K".max(5, 4 + $transactionCount).')', 'Total penjualan setelah diskon'],
            ['HPP', "=SUM('Transaksi'!L5:L".max(5, 4 + $transactionCount).')', 'Modal menu terjual'],
            ['Pengeluaran', "=SUM('Pengeluaran'!E5:E".max(5, 4 + $expenseCount).')', 'Biaya operasional'],
            ['Laba Kotor', '=B6-B7', 'Omzet dikurangi HPP'],
            ['Laba Bersih', '=B6-B7-B8', 'Setelah HPP dan pengeluaran'],
        ], null, 'A5');

        $sheet->getStyle('A1:D1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '465D8B']],
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(34);
        $sheet->getStyle('A2:A3')->getFont()->setColor(new Color('74819A'));
        $this->styleHeader($sheet, 'A5:C5');
        $sheet->getStyle('A6:B10')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('E2E7F0');
        $sheet->getStyle('B6:B10')->getNumberFormat()->setFormatCode('"Rp" #,##0');
        $sheet->getStyle('A10:C10')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EDF7']],
            'font' => ['bold' => true, 'color' => ['rgb' => '465D8B']],
        ]);
        $sheet->getColumnDimension('A')->setWidth(23);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(34);
        $sheet->getColumnDimension('D')->setWidth(4);
        $sheet->freezePane('A5');
        $sheet->getTabColor()->setRGB('465D8B');
    }

    private function buildTransactionsSheet(Worksheet $sheet, Collection $transactions, Tenant $tenant, Store $store, Carbon $from, Carbon $to, string $type, float $factor): void
    {
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:M1')->setCellValue('A1', 'RINCIAN TRANSAKSI · '.strtoupper($type === 'non_real' ? 'NON-RIIL 50%' : 'RIIL'));
        $sheet->mergeCells('A2:M2')->setCellValue('A2', $tenant->name.' · '.$store->name.' · '.$from->format('d/m/Y').'–'.$to->format('d/m/Y'));
        $headers = ['Invoice', 'Waktu', 'Layanan', 'Meja / Platform', 'Kasir', 'Member', 'Item', 'Pembayaran', 'Subtotal', 'Diskon', 'Omzet', 'HPP', 'Laba Kotor'];
        $sheet->fromArray($headers, null, 'A4');

        $row = 5;
        foreach ($transactions as $transaction) {
            $cost = $transaction->items->sum(fn ($item) => $item->cost * $item->quantity) * $factor;
            $items = $transaction->items->map(fn ($item) => $item->product_name.' × '.$item->quantity)->implode(', ');
            $service = match ($transaction->service_type) {
                'takeaway' => 'Take Away',
                'online' => 'Ojek Online',
                default => 'Dine In',
            };
            $serviceDetail = match ($transaction->service_type) {
                'dine_in' => 'Meja '.($transaction->table_number ?: '-'),
                'online' => $transaction->online_platform ?: '-',
                default => 'Dibawa pulang',
            };
            $sheet->fromArray([[
                $transaction->invoice_no,
                ExcelDate::PHPToExcel($transaction->transacted_at),
                $service,
                $serviceDetail,
                $transaction->user->name,
                $transaction->member?->name ?? 'Umum',
                $items,
                strtoupper($transaction->payment_method),
                (float) $transaction->subtotal * $factor,
                (float) $transaction->discount * $factor,
                (float) $transaction->total * $factor,
                (float) $cost,
                "=K{$row}-L{$row}",
            ]], null, "A{$row}");
            $row++;
        }

        $lastRow = max(5, $row - 1);
        $this->styleTitleRows($sheet, 'A1:M1');
        $this->styleHeader($sheet, 'A4:M4');
        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:M{$lastRow}");
        $sheet->getStyle("B5:B{$lastRow}")->getNumberFormat()->setFormatCode('dd mmm yyyy hh:mm');
        $sheet->getStyle("I5:M{$lastRow}")->getNumberFormat()->setFormatCode('"Rp" #,##0');
        $sheet->getStyle("G5:G{$lastRow}")->getAlignment()->setWrapText(true);
        foreach (['A' => 23, 'B' => 20, 'C' => 15, 'D' => 18, 'E' => 19, 'F' => 18, 'G' => 42, 'H' => 14, 'I' => 16, 'J' => 14, 'K' => 16, 'L' => 16, 'M' => 17] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getTabColor()->setRGB('637BA8');
    }

    private function buildExpensesSheet(Worksheet $sheet, Collection $expenses, Tenant $tenant, Store $store, Carbon $from, Carbon $to, string $type, float $factor): void
    {
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:E1')->setCellValue('A1', 'RINCIAN PENGELUARAN · '.strtoupper($type === 'non_real' ? 'NON-RIIL 50%' : 'RIIL'));
        $sheet->mergeCells('A2:E2')->setCellValue('A2', $tenant->name.' · '.$store->name.' · '.$from->format('d/m/Y').'–'.$to->format('d/m/Y'));
        $sheet->fromArray(['Tanggal', 'Kategori', 'Keterangan', 'Dicatat Oleh', 'Nominal'], null, 'A4');

        $row = 5;
        foreach ($expenses as $expense) {
            $sheet->fromArray([[
                ExcelDate::PHPToExcel($expense->expense_date),
                $expense->category,
                $expense->description,
                $expense->user->name,
                (float) $expense->amount * $factor,
            ]], null, "A{$row}");
            $row++;
        }

        $lastRow = max(5, $row - 1);
        $this->styleTitleRows($sheet, 'A1:E1');
        $this->styleHeader($sheet, 'A4:E4');
        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:E{$lastRow}");
        $sheet->getStyle("A5:A{$lastRow}")->getNumberFormat()->setFormatCode('dd mmm yyyy');
        $sheet->getStyle("E5:E{$lastRow}")->getNumberFormat()->setFormatCode('"Rp" #,##0');
        foreach (['A' => 17, 'B' => 20, 'C' => 40, 'D' => 20, 'E' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getTabColor()->setRGB('D39A59');
    }

    private function styleTitleRows(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '465D8B']],
            'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle('A2')->getFont()->setColor(new Color('74819A'));
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EDF7']],
            'font' => ['bold' => true, 'color' => ['rgb' => '465D8B']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E5']]],
        ]);
        $sheet->getRowDimension((int) preg_replace('/\D/', '', explode(':', $range)[0]))->setRowHeight(24);
    }
}
