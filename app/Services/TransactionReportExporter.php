<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Member;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionReportExporter
{
    public function data(int $tenantId, ?int $storeId, Carbon $from, Carbon $to, float|array $factor): array
    {
        $factorForStore = fn (int|string|null $id): float => is_array($factor)
            ? (float) ($factor[(int) $id] ?? 1)
            : $factor;
        $transactions = Transaction::with(['items', 'user', 'member', 'payments', 'store'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('transaction_type', 'sale')
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->whereBetween('transacted_at', [$from, $to])
            ->orderBy('transacted_at')
            ->get();
        $expenseRows = Expense::with('user')
            ->where('tenant_id', $tenantId)
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('expense_date')
            ->get();

        $scaledSales = $transactions->sum(fn (Transaction $transaction) => (float) $transaction->total * $factorForStore($transaction->store_id));
        $scaledCost = $transactions->sum(fn (Transaction $transaction) => $transaction->items->sum(fn ($item) => $item->cost * $item->quantity) * $factorForStore($transaction->store_id));
        $scaledExpenses = $expenseRows->sum(fn (Expense $expense) => (float) $expense->amount * $factorForStore($expense->store_id));

        $daily = $transactions->groupBy(fn (Transaction $transaction) => $transaction->transacted_at->toDateString())
            ->map(fn (Collection $rows, string $date) => (object) [
                'date' => $date,
                'total' => round($rows->sum(fn (Transaction $transaction) => (float) $transaction->total * $factorForStore($transaction->store_id)), 2),
            ])->values();
        $paymentRows = $transactions->flatMap(function (Transaction $transaction) use ($factorForStore) {
            $rowFactor = $factorForStore($transaction->store_id);
            if ($transaction->payments->isNotEmpty()) {
                return $transaction->payments->map(fn ($payment) => (object) [
                    'method' => $payment->method,
                    'provider' => $payment->provider,
                    'amount' => (float) $payment->amount * $rowFactor,
                    'transaction_id' => $transaction->id,
                ]);
            }

            return collect([(object) ['method' => $transaction->payment_method, 'provider' => null, 'amount' => (float) $transaction->total * $rowFactor, 'transaction_id' => $transaction->id]]);
        });
        $payments = $paymentRows->groupBy(fn ($row) => $row->method.'|'.($row->provider ?: '-'))
            ->map(fn (Collection $rows) => (object) [
                'payment_method' => $rows->first()->method,
                'provider' => $rows->first()->provider,
                'total' => round($rows->sum('amount'), 2),
                'count' => $rows->pluck('transaction_id')->unique()->count(),
            ])->values();
        $productSales = $transactions->flatMap(fn (Transaction $transaction) => $transaction->items->map(fn ($item) => (object) [
            'product_name' => $item->product_name,
            'quantity' => $item->quantity,
            'subtotal' => (float) $item->subtotal * $factorForStore($transaction->store_id),
        ]))->groupBy('product_name')->map(fn (Collection $items, string $name) => (object) [
            'name' => $name,
            'quantity' => $items->sum('quantity'),
            'sales' => round($items->sum('subtotal'), 2),
        ])->sortByDesc('quantity')->values();
        $newMembers = Member::where('tenant_id', $tenantId)->whereBetween('created_at', [$from, $to])->count();
        $topupRows = DB::table('deposit_transactions')->where('tenant_id', $tenantId)->where('type', 'credit')
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))->whereBetween('created_at', [$from, $to])->get();
        $topups = $topupRows->groupBy(fn ($row) => $row->payment_method ?: 'cash')->map(fn (Collection $rows, string $method) => (object) [
            'method' => $method,
            'count' => $rows->count(),
            'total' => round($rows->sum(fn ($row) => (float) $row->amount * $factorForStore($row->store_id)), 2),
        ])->values();
        $depositUsed = round($paymentRows->where('method', 'deposit')->sum('amount'), 2);
        if ($depositUsed === 0.0) {
            $depositUsed = round($transactions->where('payment_method', 'deposit')->sum(fn (Transaction $transaction) => (float) $transaction->total * $factorForStore($transaction->store_id)), 2);
        }
        $storeComparison = $transactions->groupBy('store_id')->map(fn (Collection $rows) => (object) [
            'store' => $rows->first()->store?->name ?? 'Warung',
            'sales' => round($rows->sum('total') * $factorForStore($rows->first()->store_id), 2),
            'transactions' => $rows->count(),
        ])->sortByDesc('sales')->values();

        return [
            'transactions' => $transactions,
            'expenseRows' => $expenseRows,
            'sales' => round($scaledSales, 2),
            'cost' => round($scaledCost, 2),
            'expenses' => round($scaledExpenses, 2),
            'profit' => round($scaledSales - $scaledCost - $scaledExpenses, 2),
            'daily' => $daily,
            'payments' => $payments,
            'products' => $productSales,
            'newMembers' => $newMembers,
            'topups' => $topups,
            'depositUsed' => $depositUsed,
            'turnoverNetDeposit' => round($scaledSales - $depositUsed, 2),
            'storeComparison' => $storeComparison,
        ];
    }

    public function workbook(array $data, Tenant $tenant, Store $store, Carbon $from, Carbon $to, string $type, float|array $factor): Spreadsheet
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
        $this->buildSummarySheet($summary, $tenant, $store, $from, $to, $type, $factor, count($data['transactions']), count($data['expenseRows']));

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildSummarySheet(Worksheet $sheet, Tenant $tenant, Store $store, Carbon $from, Carbon $to, string $type, float|array $factor, int $transactionCount, int $expenseCount): void
    {
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:D1')->setCellValue('A1', 'LAPORAN TRANSAKSI');
        $sheet->mergeCells('A2:D2')->setCellValue('A2', $tenant->name.' · '.$store->name);
        $sheet->mergeCells('A3:D3')->setCellValue('A3', $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y').' · '.($type === 'non_real' ? $this->factorLabel($factor) : 'Riil'));

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

    private function buildTransactionsSheet(Worksheet $sheet, Collection $transactions, Tenant $tenant, Store $store, Carbon $from, Carbon $to, string $type, float|array $factor): void
    {
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:M1')->setCellValue('A1', 'RINCIAN TRANSAKSI · '.strtoupper($type === 'non_real' ? $this->factorLabel($factor) : 'RIIL'));
        $sheet->mergeCells('A2:M2')->setCellValue('A2', $tenant->name.' · '.$store->name.' · '.$from->format('d/m/Y').'–'.$to->format('d/m/Y'));
        $headers = ['Invoice', 'Waktu', 'Layanan', 'Meja / Platform', 'Kasir', 'Member', 'Item', 'Pembayaran', 'Subtotal', 'Diskon', 'Omzet', 'HPP', 'Laba Kotor'];
        $sheet->fromArray($headers, null, 'A4');

        $row = 5;
        foreach ($transactions as $transaction) {
            $rowFactor = $this->factorForStore($factor, $transaction->store_id);
            $cost = $transaction->items->sum(fn ($item) => $item->cost * $item->quantity) * $rowFactor;
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
                (float) $transaction->subtotal * $rowFactor,
                (float) $transaction->discount * $rowFactor,
                (float) $transaction->total * $rowFactor,
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

    private function buildExpensesSheet(Worksheet $sheet, Collection $expenses, Tenant $tenant, Store $store, Carbon $from, Carbon $to, string $type, float|array $factor): void
    {
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:E1')->setCellValue('A1', 'RINCIAN PENGELUARAN · '.strtoupper($type === 'non_real' ? $this->factorLabel($factor) : 'RIIL'));
        $sheet->mergeCells('A2:E2')->setCellValue('A2', $tenant->name.' · '.$store->name.' · '.$from->format('d/m/Y').'–'.$to->format('d/m/Y'));
        $sheet->fromArray(['Tanggal', 'Kategori', 'Keterangan', 'Dicatat Oleh', 'Nominal'], null, 'A4');

        $row = 5;
        foreach ($expenses as $expense) {
            $rowFactor = $this->factorForStore($factor, $expense->store_id);
            $sheet->fromArray([[
                ExcelDate::PHPToExcel($expense->expense_date),
                $expense->category,
                $expense->description,
                $expense->user->name,
                (float) $expense->amount * $rowFactor,
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

    private function factorForStore(float|array $factor, int|string|null $storeId): float
    {
        return is_array($factor) ? (float) ($factor[(int) $storeId] ?? 1) : $factor;
    }

    private function factorLabel(float|array $factor): string
    {
        return is_array($factor)
            ? 'Non-riil (persentase per cabang)'
            : 'Non-riil ('.round($factor * 100, 2).'%)';
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
