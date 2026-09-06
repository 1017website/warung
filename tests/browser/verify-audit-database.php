<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'warungkita_audit_20260906') {
    throw new RuntimeException('Audit database only');
}
$f = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents(__DIR__.'/../../storage/app/fix-verification/fixture.json')), true);
$transactions = App\Models\Transaction::where('tenant_id', $f['tenant'])->with('payments')->orderBy('id')->get();
$purchase = App\Models\Purchase::findOrFail($f['purchase']);
$stock = App\Models\ProductStock::where('store_id', $f['store'])->where('product_id', $f['raw'])->value('quantity');
$menuStock = App\Models\DailyMenuStock::where('store_id', $f['store'])->where('product_id', $f['menu'])->whereDate('stock_date', today())->value('quantity');
$balance = App\Models\Member::findOrFail($f['member'])->deposit_balance;
$checks = [
    'only_two_valid_checkouts_saved' => $transactions->count() === 2,
    'cash_received_30000_change_5000' => (float) $transactions[0]->paid_amount === 30000.0 && (float) $transactions[0]->change_amount === 5000.0,
    'split_deposit_5000_cash_20000' => (float) $transactions[1]->payments->firstWhere('method', 'deposit')->amount === 5000.0 && (float) $transactions[1]->payments->firstWhere('method', 'cash')->amount === 20000.0,
    'deposit_balance_95000' => (float) $balance === 95000.0,
    'stock_only_decreased_by_two' => (float) $menuStock === 5.0,
    'dp_one_million_preserved' => (float) $purchase->dp_amount === 1000000.0,
    'metadata_updated' => $purchase->supplier_name === 'Supplier dikoreksi',
    'purchase_total_unchanged' => (float) $purchase->total === 2000000.0,
    'raw_stock_unchanged' => (float) $stock === 2.0,
];
echo json_encode(['checks' => $checks, 'passed' => ! in_array(false, $checks, true)], JSON_PRETTY_PRINT);
if (in_array(false, $checks, true)) { exit(1); }
