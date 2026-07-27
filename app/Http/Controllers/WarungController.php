<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\DailyMenuStock;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionReportExporter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WarungController extends Controller
{
    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    private function storeId(): int
    {
        return (int) (session('store_id') ?: auth()->user()->store_id);
    }

    private function stores()
    {
        return Store::where('tenant_id', $this->tenantId())->where('is_active', true)->get();
    }

    private function view(string $name, array $data = [])
    {
        return view($name, $data + ['activeStore' => Store::find($this->storeId()), 'availableStores' => $this->stores()]);
    }

    private function reportRange(Request $request): array
    {
        $request->validate([
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'year', 'custom'])],
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $period = $request->string('period')->toString();
        if ($period === '') {
            $period = $request->filled('from') || $request->filled('to') ? 'custom' : 'month';
        }

        [$from, $to] = match ($period) {
            'today' => [today()->startOfDay(), today()->endOfDay()],
            'week' => [now()->startOfWeek()->startOfDay(), now()->endOfWeek()->endOfDay()],
            'year' => [now()->startOfYear()->startOfDay(), now()->endOfYear()->endOfDay()],
            'custom' => [
                Carbon::parse($request->get('from', now()->startOfMonth()->toDateString()))->startOfDay(),
                Carbon::parse($request->get('to', now()->toDateString()))->endOfDay(),
            ],
            default => [now()->startOfMonth()->startOfDay(), now()->endOfMonth()->endOfDay()],
        };

        return [$period, $from, $to];
    }

    public function switchStore(Request $request)
    {
        $store = Store::where('tenant_id', $this->tenantId())->whereKey($request->validate(['store_id' => 'required|integer'])['store_id'])->firstOrFail();
        $request->session()->put('store_id', $store->id);

        return back()->with('success', 'Cabang aktif diubah ke '.$store->name.'.');
    }

    public function dashboard()
    {
        $today = today();
        $transactions = Transaction::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->whereDate('transacted_at', $today);
        $sales = (clone $transactions)->sum('total');
        $transactionCount = (clone $transactions)->count();
        $expenses = Expense::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->whereDate('expense_date', $today)->sum('amount');
        $ingredientLowStocks = ProductStock::with('product')->where('store_id', $this->storeId())
            ->whereHas('product', fn ($q) => $q->where('tenant_id', $this->tenantId())->where('product_type', 'ingredient'))
            ->get()->filter(fn ($stock) => $stock->quantity <= $stock->product->minimum_stock);
        $menuLowStocks = DailyMenuStock::with('product')->where('store_id', $this->storeId())->whereDate('stock_date', $today)
            ->whereHas('product', fn ($q) => $q->where('tenant_id', $this->tenantId())->where('product_type', 'menu'))
            ->get()->filter(fn ($stock) => $stock->quantity <= $stock->product->minimum_stock);
        $lowStocks = $ingredientLowStocks->concat($menuLowStocks)->sortBy('quantity')->take(5);
        $latest = (clone $transactions)->with('member')->latest('transacted_at')->take(5)->get();
        $week = collect(range(6, 0))->map(function ($days) {
            $date = today()->subDays($days);

            return [
                'label' => $date->translatedFormat('D'),
                'value' => Transaction::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->whereDate('transacted_at', $date)->sum('total'),
            ];
        });

        return $this->view('dashboard', compact('sales', 'transactionCount', 'expenses', 'lowStocks', 'latest', 'week'));
    }

    public function pos()
    {
        $products = Product::with(['category', 'dailyStocks' => fn ($q) => $q->where('store_id', $this->storeId())->whereDate('stock_date', today())])
            ->where('tenant_id', $this->tenantId())->where('product_type', 'menu')->where('is_active', true)->orderBy('name')->get();
        $categories = Category::where('tenant_id', $this->tenantId())->whereHas('products', fn ($q) => $q->where('product_type', 'menu'))->orderBy('name')->get();
        $members = Member::where('tenant_id', $this->tenantId())->where('is_active', true)->orderBy('name')->get();
        $posProducts = $products->map(fn ($product) => [
            'id' => $product->id,
            'name' => $product->name,
            'price' => (float) $product->selling_price,
            'stock' => $product->dailyStocks->first()?->quantity ?? 0,
        ])->values();

        return $this->view('pos.index', compact('products', 'categories', 'members', 'posProducts'));
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'member_id' => ['nullable', 'integer'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(['cash', 'transfer', 'qris', 'deposit'])],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'service_type' => ['required', Rule::in(['dine_in', 'takeaway', 'online'])],
            'table_number' => ['nullable', 'string', 'max:20', Rule::requiredIf(fn () => $request->input('service_type') === 'dine_in')],
            'online_platform' => ['nullable', 'string', 'max:30', Rule::requiredIf(fn () => $request->input('service_type') === 'online')],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $transaction = DB::transaction(function () use ($data) {
            $lines = collect($data['items'])->map(function ($line) {
                $product = Product::where('tenant_id', $this->tenantId())->where('product_type', 'menu')->lockForUpdate()->findOrFail($line['id']);
                $stock = DailyMenuStock::firstOrCreate(
                    ['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'stock_date' => today()],
                    ['quantity' => 0]
                );
                $stock->refresh();
                abort_if($stock->quantity < $line['qty'], 422, "Stok {$product->name} tidak mencukupi.");

                return ['product' => $product, 'stock' => $stock, 'qty' => (int) $line['qty'], 'subtotal' => $product->selling_price * $line['qty']];
            });

            $subtotal = $lines->sum('subtotal');
            $discount = min((float) ($data['discount'] ?? 0), $subtotal);
            $total = $subtotal - $discount;
            $member = ! empty($data['member_id']) ? Member::where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($data['member_id']) : null;

            if ($data['payment_method'] === 'deposit') {
                abort_unless($member, 422, 'Pilih member untuk pembayaran deposit.');
                abort_if($member->deposit_balance < $total, 422, 'Saldo deposit member tidak mencukupi.');
            }

            $paid = $data['payment_method'] === 'cash' ? (float) ($data['paid_amount'] ?? 0) : $total;
            abort_if($paid < $total, 422, 'Nominal pembayaran kurang.');
            $invoice = 'TRX-'.now()->format('ymd-His').'-'.strtoupper(Str::random(3));

            $trx = Transaction::create([
                'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(),
                'member_id' => $member?->id, 'invoice_no' => $invoice, 'report_type' => 'real',
                'service_type' => $data['service_type'],
                'table_number' => $data['service_type'] === 'dine_in' ? $data['table_number'] : null,
                'online_platform' => $data['service_type'] === 'online' ? $data['online_platform'] : null,
                'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total,
                'payment_method' => $data['payment_method'], 'paid_amount' => $paid,
                'change_amount' => max(0, $paid - $total), 'notes' => $data['notes'] ?? null, 'transacted_at' => now(),
            ]);

            foreach ($lines as $line) {
                $trx->items()->create([
                    'product_id' => $line['product']->id, 'product_name' => $line['product']->name,
                    'quantity' => $line['qty'], 'price' => $line['product']->selling_price,
                    'cost' => $line['product']->purchase_price, 'subtotal' => $line['subtotal'],
                ]);
                $line['stock']->decrement('quantity', $line['qty']);
                DB::table('stock_movements')->insert([
                    'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $line['product']->id,
                    'user_id' => auth()->id(), 'type' => 'sale', 'quantity' => -$line['qty'], 'reference' => $invoice,
                    'notes' => 'Penjualan POS', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if ($data['payment_method'] === 'deposit' && $member) {
                $member->decrement('deposit_balance', $total);
                DB::table('deposit_transactions')->insert([
                    'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'member_id' => $member->id,
                    'user_id' => auth()->id(), 'transaction_id' => $trx->id, 'type' => 'debit', 'amount' => $total,
                    'balance_after' => $member->fresh()->deposit_balance, 'description' => "Pembayaran {$invoice}",
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $trx;
        });

        return response()->json(['ok' => true, 'invoice' => $transaction->invoice_no, 'print_url' => route('transactions.print', $transaction)]);
    }

    public function products()
    {
        $products = Product::with('category')
            ->withSum(['stocks as warehouse_stock' => fn ($q) => $q->where('store_id', $this->storeId())], 'quantity')
            ->withSum(['dailyStocks as daily_stock' => fn ($q) => $q->where('store_id', $this->storeId())->whereDate('stock_date', today())], 'quantity')
            ->where('tenant_id', $this->tenantId())->latest()->paginate(12);
        $categories = Category::where('tenant_id', $this->tenantId())->orderBy('name')->get();

        return $this->view('products.index', compact('products', 'categories'));
    }

    public function storeProduct(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'sku' => 'required|string|max:50',
            'barcode' => 'nullable|string|max:80',
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
            'unit' => 'required|string|max:20', 'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0', 'minimum_stock' => 'required|integer|min:0',
            'product_type' => ['required', Rule::in(['menu', 'ingredient'])],
            'initial_stock' => 'nullable|integer|min:0',
        ]);
        $exists = Product::where('tenant_id', $this->tenantId())->where('sku', $data['sku'])->exists();
        if ($exists) {
            return back()->withErrors(['sku' => 'SKU sudah digunakan.'])->withInput();
        }
        $initial = $data['initial_stock'] ?? 0;
        unset($data['initial_stock']);
        $product = Product::create($data + ['tenant_id' => $this->tenantId(), 'is_active' => true]);
        if ($product->product_type === 'menu') {
            DailyMenuStock::create(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'stock_date' => today(), 'quantity' => $initial]);
        } else {
            ProductStock::create(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'quantity' => $initial]);
        }

        return back()->with('success', 'Produk berhasil ditambahkan.');
    }

    public function updateProduct(Request $request, Product $product)
    {
        abort_unless($product->tenant_id === $this->tenantId(), 404);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'sku' => ['required', 'string', 'max:50', Rule::unique('products')->where('tenant_id', $this->tenantId())->ignore($product->id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('products')->where('tenant_id', $this->tenantId())->ignore($product->id)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
            'unit' => 'required|string|max:20', 'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0', 'minimum_stock' => 'required|integer|min:0',
        ]);
        $product->update($data);

        return back()->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroyProduct(Product $product)
    {
        abort_unless($product->tenant_id === $this->tenantId(), 404);
        $product->delete();

        return back()->with('success', 'Produk dipindahkan ke arsip.');
    }

    public function inventory()
    {
        Product::where('tenant_id', $this->tenantId())->where('product_type', 'menu')->where('is_active', true)->each(function ($product) {
            DailyMenuStock::firstOrCreate(
                ['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'stock_date' => today()],
                ['quantity' => 0]
            );
        });
        $ingredientStocks = ProductStock::with('product.category')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())
            ->whereHas('product', fn ($q) => $q->where('product_type', 'ingredient'))->orderBy('quantity')->paginate(12, ['*'], 'bahan');
        $menuStocks = DailyMenuStock::with('product.category')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->whereDate('stock_date', today())
            ->whereHas('product', fn ($q) => $q->where('product_type', 'menu'))->orderBy('quantity')->paginate(12, ['*'], 'menu');
        $inventoryProducts = Product::where('tenant_id', $this->tenantId())->where('is_active', true)
            ->orderBy('product_type')->orderBy('name')->get();
        $movements = DB::table('stock_movements')->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.tenant_id', $this->tenantId())->where('stock_movements.store_id', $this->storeId())
            ->select('stock_movements.*', 'products.name as product_name')->latest('stock_movements.created_at')->take(8)->get();

        return $this->view('inventory.index', compact('ingredientStocks', 'menuStocks', 'inventoryProducts', 'movements'));
    }

    public function adjustStock(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer', 'type' => ['required', Rule::in(['adjustment_in', 'adjustment_out'])], 'quantity' => 'required|integer|min:1', 'notes' => 'required|string|max:255']);
        $product = Product::where('tenant_id', $this->tenantId())->findOrFail($data['product_id']);
        $stock = $product->product_type === 'menu'
            ? DailyMenuStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'stock_date' => today()], ['quantity' => 0])
            : ProductStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id], ['quantity' => 0]);
        $delta = $data['type'] === 'adjustment_in' ? $data['quantity'] : -$data['quantity'];
        abort_if($stock->quantity + $delta < 0, 422, 'Stok tidak boleh menjadi negatif.');
        $stock->increment('quantity', $delta);
        DB::table('stock_movements')->insert($data + ['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(), 'quantity' => $delta, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Stok berhasil disesuaikan.');
    }

    public function purchases()
    {
        $purchases = Purchase::with('items')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->latest('purchased_at')->paginate(12);
        $products = Product::where('tenant_id', $this->tenantId())->where('product_type', 'ingredient')->orderBy('name')->get();

        return $this->view('purchases.index', compact('purchases', 'products'));
    }

    public function storePurchase(Request $request)
    {
        $data = $request->validate(['supplier_name' => 'required|string|max:120', 'product_id' => 'required|integer', 'quantity' => 'required|integer|min:1', 'unit_cost' => 'required|numeric|min:0', 'purchased_at' => 'required|date', 'notes' => 'nullable|string|max:255']);
        $product = Product::where('tenant_id', $this->tenantId())->where('product_type', 'ingredient')->findOrFail($data['product_id']);
        $total = $data['quantity'] * $data['unit_cost'];
        DB::transaction(function () use ($data, $product, $total) {
            $no = 'PO-'.now()->format('ymd-His').'-'.strtoupper(Str::random(2));
            $purchase = Purchase::create(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(), 'purchase_no' => $no, 'supplier_name' => $data['supplier_name'], 'total' => $total, 'status' => 'received', 'purchased_at' => $data['purchased_at'], 'notes' => $data['notes'] ?? null]);
            $purchase->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $data['quantity'], 'unit_cost' => $data['unit_cost'], 'subtotal' => $total]);
            ProductStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id], ['quantity' => 0])->increment('quantity', $data['quantity']);
            $product->update(['purchase_price' => $data['unit_cost']]);
            DB::table('stock_movements')->insert(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'user_id' => auth()->id(), 'type' => 'purchase', 'quantity' => $data['quantity'], 'reference' => $no, 'notes' => 'Penerimaan pembelian', 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Pembelian diterima dan stok otomatis bertambah.');
    }

    public function expenses()
    {
        $expenses = Expense::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->latest('expense_date')->paginate(12);

        return $this->view('expenses.index', compact('expenses'));
    }

    public function storeExpense(Request $request)
    {
        $data = $request->validate(['category' => 'required|string|max:60', 'description' => 'required|string|max:180', 'amount' => 'required|numeric|min:1', 'expense_date' => 'required|date']);
        Expense::create($data + ['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(), 'report_type' => 'real']);

        return back()->with('success', 'Pengeluaran berhasil dicatat.');
    }

    public function destroyExpense(Expense $expense)
    {
        abort_unless($expense->tenant_id === $this->tenantId(), 404);
        $expense->delete();

        return back()->with('success', 'Pengeluaran dipindahkan ke arsip.');
    }

    public function members()
    {
        $members = Member::where('tenant_id', $this->tenantId())->latest()->paginate(12);

        return $this->view('members.index', compact('members'));
    }

    public function storeMember(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:120']);
        $next = (Member::where('tenant_id', $this->tenantId())->withTrashed()->max('id') ?? 0) + 1;
        Member::create($data + ['tenant_id' => $this->tenantId(), 'member_code' => 'MBR-'.str_pad($next, 5, '0', STR_PAD_LEFT), 'qr_code' => (string) Str::uuid(), 'is_active' => true]);

        return back()->with('success', 'Member baru berhasil dibuat.');
    }

    public function topup(Request $request, Member $member)
    {
        abort_unless($member->tenant_id === $this->tenantId(), 404);
        $data = $request->validate(['amount' => 'required|numeric|min:1000']);
        DB::transaction(function () use ($member, $data) {
            $lockedMember = Member::where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($member->id);
            $lockedMember->increment('deposit_balance', $data['amount']);
            DB::table('deposit_transactions')->insert(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'member_id' => $lockedMember->id, 'user_id' => auth()->id(), 'transaction_id' => null, 'type' => 'credit', 'amount' => $data['amount'], 'balance_after' => $lockedMember->fresh()->deposit_balance, 'description' => 'Top up deposit', 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Deposit berhasil ditambahkan.');
    }

    public function findMember(string $code)
    {
        $member = Member::where('tenant_id', $this->tenantId())->where(fn ($q) => $q->where('qr_code', $code)->orWhere('member_code', $code))->firstOrFail();

        return response()->json($member->only('id', 'name', 'member_code', 'deposit_balance'));
    }

    public function transactions()
    {
        $transactions = Transaction::with(['member', 'user'])->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->latest('transacted_at')->paginate(15);

        return $this->view('transactions.index', compact('transactions'));
    }

    public function print(Transaction $transaction)
    {
        abort_unless($transaction->tenant_id === $this->tenantId(), 404);
        $transaction->load('items', 'member', 'user', 'store');

        return view('transactions.print', ['transaction' => $transaction, 'tenant' => auth()->user()->tenant]);
    }

    public function destroyTransaction(Transaction $transaction)
    {
        abort_unless($transaction->tenant_id === $this->tenantId(), 404);
        $transaction->delete();

        return back()->with('success', 'Transaksi dipindahkan ke arsip. Stok tidak diubah otomatis demi audit.');
    }

    public function reports(Request $request, TransactionReportExporter $exporter)
    {
        $canSeeNonReal = in_array(auth()->user()->role, ['owner', 'superadmin']);
        $requestedType = $request->get('type');
        $type = $canSeeNonReal && $requestedType === 'non_real' ? 'non_real' : 'real';
        $factor = $type === 'non_real' ? 0.5 : 1;
        [$period, $from, $to] = $this->reportRange($request);
        $data = $exporter->data($this->tenantId(), $this->storeId(), $from, $to, $factor);
        ['sales' => $sales, 'cost' => $cost, 'expenses' => $expenses, 'profit' => $profit, 'daily' => $daily, 'payments' => $payments, 'transactions' => $transactionRows] = $data;

        return $this->view('reports.index', compact('type', 'factor', 'period', 'from', 'to', 'sales', 'cost', 'expenses', 'profit', 'daily', 'payments', 'transactionRows', 'canSeeNonReal'));
    }

    public function exportReport(Request $request, TransactionReportExporter $exporter)
    {
        $canSeeNonReal = in_array(auth()->user()->role, ['owner', 'superadmin']);
        $type = $canSeeNonReal && $request->get('type') === 'non_real' ? 'non_real' : 'real';
        $factor = $type === 'non_real' ? 0.5 : 1;
        [, $from, $to] = $this->reportRange($request);
        $store = Store::where('tenant_id', $this->tenantId())->findOrFail($this->storeId());
        $tenant = auth()->user()->tenant;
        $data = $exporter->data($this->tenantId(), $this->storeId(), $from, $to, $factor);
        $spreadsheet = $exporter->workbook($data, $tenant, $store, $from, $to, $type, $factor);
        $filename = 'laporan-transaksi-'.str_replace('_', '-', $type).'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    public function settings()
    {
        $users = User::where('tenant_id', $this->tenantId())->orderBy('name')->get();
        $creatableRoles = match (auth()->user()->role) {
            'superadmin' => ['owner' => 'Owner', 'admin' => 'Admin', 'cashier' => 'Kasir', 'warehouse' => 'Gudang'],
            'owner' => ['admin' => 'Admin', 'cashier' => 'Kasir', 'warehouse' => 'Gudang'],
            default => ['cashier' => 'Kasir', 'warehouse' => 'Gudang'],
        };

        return $this->view('settings.index', ['tenant' => auth()->user()->tenant, 'stores' => $this->stores(), 'users' => $users, 'creatableRoles' => $creatableRoles]);
    }

    public function updateBrand(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'logo' => 'nullable|image|max:2048']);
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('branding', 'public');
            $data['logo_path'] = $path;
        }
        unset($data['logo']);
        auth()->user()->tenant->update($data);

        return back()->with('success', 'Identitas warung berhasil diperbarui.');
    }

    public function storeBranch(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'code' => ['required', 'string', 'max:20', Rule::unique('stores')->where('tenant_id', $this->tenantId())], 'address' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:30']);
        Store::create($data + ['tenant_id' => $this->tenantId(), 'is_active' => true]);

        return back()->with('success', 'Cabang baru berhasil ditambahkan.');
    }

    public function storeUser(Request $request)
    {
        $allowedRoles = match (auth()->user()->role) {
            'superadmin' => ['owner', 'admin', 'cashier', 'warehouse'],
            'owner' => ['admin', 'cashier', 'warehouse'],
            default => ['cashier', 'warehouse'],
        };
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|unique:users,email', 'role' => ['required', Rule::in($allowedRoles)], 'store_id' => 'required|integer', 'password' => 'required|string|min:8']);
        abort_unless(Store::where('tenant_id', $this->tenantId())->whereKey($data['store_id'])->exists(), 422);
        User::create($data + ['tenant_id' => $this->tenantId(), 'password' => Hash::make($data['password']), 'is_active' => true]);

        return back()->with('success', 'Pengguna baru berhasil ditambahkan.');
    }
}
