<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use Tests\TestCase;

class ReceiptPrintTest extends TestCase
{
    public function test_receipts_group_interleaved_items_and_keep_payment_off_kitchen_copy(): void
    {
        $store = new Store(['name' => 'Cabang Contoh', 'business_name' => 'Warung Contoh', 'receipt_sort_by_category' => false]);
        $transaction = new Transaction([
            'invoice_no' => 'TRX-CONTOH', 'transacted_at' => now(), 'service_type' => 'dine_in',
            'table_number' => '05', 'subtotal' => 50000, 'total' => 45000, 'discount' => 5000,
            'discount_type' => 'percent', 'discount_value' => 10, 'paid_amount' => 60000, 'change_amount' => 15000,
        ]);
        $transaction->setRelation('store', $store);
        $transaction->setRelation('user', new User(['name' => 'Kasir Contoh']));
        $transaction->setRelation('member', null);
        $transaction->setRelation('payments', collect([new TransactionPayment(['method' => 'cash', 'amount' => 60000])]));
        $transaction->setRelation('items', collect([
            new TransactionItem(['category_name' => 'Minuman', 'product_name' => 'Es Teh', 'quantity' => 2, 'price' => 5000, 'subtotal' => 10000]),
            new TransactionItem(['category_name' => 'Makanan', 'product_name' => 'Nasi Goreng', 'quantity' => 2, 'price' => 20000, 'subtotal' => 40000]),
            new TransactionItem(['category_name' => 'Minuman', 'product_name' => 'Air Putih', 'quantity' => 1, 'price' => 0, 'subtotal' => 0]),
            new TransactionItem(['category_name' => null, 'product_name' => '<Menu custom>', 'is_custom' => true, 'quantity' => 0.5, 'price' => 0, 'subtotal' => 0]),
        ]));

        $html = view('transactions.print', ['transaction' => $transaction, 'receiptStore' => $store])->render();
        preg_match_all('/<article\b[^>]*>(.*?)<\/article>/s', $html, $copies);
        $this->assertCount(2, $copies[1]);
        [$customer, $kitchen] = $copies[1];
        foreach ([$customer, $kitchen] as $copy) {
            $this->assertSame(1, substr_count($copy, '<div class="category">Minuman</div>'));
            $this->assertStringContainsString('<div class="category">Umum</div>', $copy);
            $this->assertStringContainsString('&lt;Menu custom&gt;', $copy);
            $this->assertLessThan(strpos($copy, 'Es Teh'), strpos($copy, 'Nasi Goreng'));
            $this->assertStringContainsString('05', $copy);
        }
        $this->assertStringContainsString('Rp 45.000', $customer);
        $this->assertStringContainsString('Rp 15.000', $customer);
        $this->assertStringContainsString('CASH', $customer);
        $this->assertStringNotContainsString('Rp ', $kitchen);
        $this->assertStringNotContainsString('CASH', $kitchen);
        $this->assertStringContainsString('2 × Nasi Goreng', $kitchen);
        $this->assertStringContainsString('0,5 ×', $kitchen);
        $this->assertStringContainsString('width:58mm', $html);
    }
}
