<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$store = new App\Models\Store(['name' => 'Warung Uji Cetak', 'address' => 'Jl. Contoh 12', 'phone' => '0800000000', 'receipt_show_logo' => false]);
$store->setRelation('tenant', new App\Models\Tenant(['name' => 'Warung Uji Cetak']));
$transaction = new App\Models\Transaction([
    'id' => 1, 'invoice_no' => 'RAWBT-PREVIEW-001', 'status' => 'completed', 'transacted_at' => now(),
    'service_type' => 'takeaway', 'transaction_type' => 'sale', 'subtotal' => 12000, 'total' => 12000,
    'payment_method' => 'cash', 'paid_amount' => 20000, 'change_amount' => 8000,
]);
$transaction->setRelation('store', $store);
$transaction->setRelation('user', new App\Models\User(['name' => 'Kasir Uji']));
$transaction->setRelation('member', null);
$transaction->setRelation('payments', collect());
$transaction->setRelation('items', collect([new App\Models\TransactionItem([
    'product_name' => 'Es Teh', 'category_name' => 'Minuman', 'quantity' => 2, 'price' => 6000, 'subtotal' => 12000,
])]));
$html = view('transactions.print', [
    'transaction' => $transaction, 'receiptStore' => $store, 'eposPrinter' => null,
    'rawbtPrinter' => new App\Models\ConnectedDevice(['name' => 'Xantri BT-58D Pro', 'driver' => 'rawbt']),
])->render();
file_put_contents(storage_path('app/rawbt-preview.html'), $html);
echo "Rendered storage/app/rawbt-preview.html\n";
