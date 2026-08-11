<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ConnectedDevice;
use App\Models\DailyMenuStock;
use App\Models\Expense;
use App\Models\Member;
use App\Models\MemberCard;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\StockCount;
use App\Models\StockProduction;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionReportExporter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WarungController extends Controller
{
    private ?Store $activeStoreRecord = null;

    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    private function storeId(): int
    {
        // Selain superadmin & head of ops, cabang aktif dipaku ke cabang milik akun
        // sehingga session tidak dapat dipakai untuk melihat warung lain.
        if (! auth()->user()->canAccessAllStores()) {
            return (int) auth()->user()->store_id;
        }

        return (int) (session('store_id') ?: auth()->user()->store_id);
    }

    private function isConsolidated(): bool
    {
        return session('view_scope') === 'consolidated' && auth()->user()->canAccessAllStores();
    }

    private function stores()
    {
        return Store::where('tenant_id', $this->tenantId())->where('is_active', true)
            ->unless(auth()->user()->canAccessAllStores(), fn ($q) => $q->whereKey(auth()->user()->store_id))
            ->orderBy('name')->get();
    }

    private function activeStoreRecord(): Store
    {
        if ($this->activeStoreRecord?->id === $this->storeId()) {
            return $this->activeStoreRecord;
        }

        return $this->activeStoreRecord = Store::with('tenant')
            ->where('tenant_id', $this->tenantId())
            ->whereKey($this->storeId())
            ->firstOrFail();
    }

    private function requestedSettingsStore(Request $request): Store
    {
        $storeId = (int) $request->input('store_id', $this->storeId());
        $this->assertStoreAccess($storeId);

        return Store::with('tenant')->where('tenant_id', $this->tenantId())->findOrFail($storeId);
    }

    private function assertStoreAccess(?int $storeId): void
    {
        abort_unless(auth()->user()->canAccessStore($storeId), 404);
    }

    private function view(string $name, array $data = [])
    {
        $stores = $this->stores();
        $activeStore = $stores->firstWhere('id', $this->storeId()) ?: $stores->first();
        $activeStore?->loadMissing('tenant');

        return view($name, $data + [
            'activeStore' => $activeStore,
            'availableStores' => $stores,
            'isConsolidated' => $this->isConsolidated(),
        ]);
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

    private function reportFactor(string $type): float|array
    {
        if ($type !== 'non_real') {
            return 1;
        }

        if (! $this->isConsolidated()) {
            return (float) $this->activeStoreRecord()->non_real_percentage / 100;
        }

        return Store::where('tenant_id', $this->tenantId())->where('is_active', true)
            ->pluck('non_real_percentage', 'id')
            ->map(fn ($percentage) => (float) $percentage / 100)
            ->all();
    }

    private function supervisorByPin(?string $pin): ?User
    {
        if (! $pin) {
            return null;
        }

        $supervisorKeys = Role::where('tenant_id', $this->tenantId())->where('is_supervisor', true)->pluck('key');

        return User::where('tenant_id', $this->tenantId())
            ->where('is_active', true)
            ->whereIn('role', $supervisorKeys)
            ->get()
            ->first(fn (User $user) => $user->authorization_pin && Hash::check($pin, $user->authorization_pin));
    }

    public function switchStore(Request $request)
    {
        $value = $request->validate(['store_id' => 'required'])['store_id'];
        if ($value === 'consolidated') {
            abort_unless(auth()->user()->canAccessAllStores(), 403);
            $request->session()->put('view_scope', 'consolidated');

            return back()->with('success', 'Tampilan consolidated aktif untuk seluruh warung.');
        }

        abort_unless(auth()->user()->canAccessStore($value), 403);
        $store = Store::where('tenant_id', $this->tenantId())->whereKey((int) $value)->firstOrFail();
        $request->session()->put('store_id', $store->id);
        $request->session()->put('view_scope', 'store');

        return back()->with('success', 'Cabang aktif diubah ke '.$store->name.'.');
    }

    public function dashboard()
    {
        $today = today();
        $transactions = Transaction::where('tenant_id', $this->tenantId())
            ->where('status', 'completed')->where('transaction_type', 'sale')->whereDate('transacted_at', $today);
        $expensesQuery = Expense::where('tenant_id', $this->tenantId())->whereDate('expense_date', $today);
        if (! $this->isConsolidated()) {
            $transactions->where('store_id', $this->storeId());
            $expensesQuery->where('store_id', $this->storeId());
        }
        $sales = (clone $transactions)->sum('total');
        $transactionCount = (clone $transactions)->count();
        $expenses = $expensesQuery->sum('amount');

        $ingredientLowStocks = ProductStock::with(['product', 'store'])->whereHas('product', fn ($q) => $q->where('tenant_id', $this->tenantId())->where('product_type', 'ingredient'));
        $menuLowStocks = DailyMenuStock::with(['product', 'store'])->whereDate('stock_date', $today)
            ->whereHas('product', fn ($q) => $q->where('tenant_id', $this->tenantId())->where('product_type', 'menu'));
        if (! $this->isConsolidated()) {
            $ingredientLowStocks->where('store_id', $this->storeId());
            $menuLowStocks->where('store_id', $this->storeId());
        }
        $lowStocks = $ingredientLowStocks->get()->concat($menuLowStocks->get())
            ->filter(fn ($stock) => $stock->quantity <= $stock->product->minimum_stock)->sortBy('quantity')->take(8);
        $latest = (clone $transactions)->with(['member', 'store'])->latest('transacted_at')->take(8)->get();
        $week = collect(range(6, 0))->map(function ($days) {
            $query = Transaction::where('tenant_id', $this->tenantId())->where('status', 'completed')->where('transaction_type', 'sale');
            if (! $this->isConsolidated()) {
                $query->where('store_id', $this->storeId());
            }
            $date = today()->subDays($days);

            return ['label' => $date->translatedFormat('D'), 'value' => $query->whereDate('transacted_at', $date)->sum('total')];
        });

        return $this->view('dashboard', compact('sales', 'transactionCount', 'expenses', 'lowStocks', 'latest', 'week'));
    }

    public function pos()
    {
        $products = Product::with(['category', 'dailyStocks' => fn ($q) => $q->where('store_id', $this->storeId())->whereDate('stock_date', today())])
            ->where('tenant_id', $this->tenantId())->where('product_type', 'menu')->where('is_active', true)->orderBy('name')->get();
        $categories = Category::where('tenant_id', $this->tenantId())->whereHas('products', fn ($q) => $q->where('product_type', 'menu'))->orderBy('name')->get();
        $members = Member::where('tenant_id', $this->tenantId())->where('is_active', true)->orderBy('name')->get();
        $pendingBills = Transaction::with(['items', 'member'])->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->where('status', 'pending')->latest()->get();
        $pendingBillData = $pendingBills->map(fn ($bill) => [
            'id' => $bill->id,
            'invoice' => $bill->invoice_no,
            'member_id' => $bill->member_id,
            'service_type' => $bill->service_type,
            'table_number' => $bill->table_number,
            'online_platform' => $bill->online_platform,
            'discount_type' => $bill->discount_type,
            'discount_value' => (float) $bill->discount_value,
            'items' => $bill->items->map(fn ($item) => [
                'id' => $item->product_id,
                'name' => $item->product_name,
                'price' => (float) $item->price,
                'qty' => (float) $item->quantity,
                'custom' => (bool) $item->is_custom,
            ])->values(),
        ])->values();
        $posProducts = $products->map(fn ($product) => [
            'id' => $product->id,
            'name' => $product->name,
            'price' => (float) $product->selling_price,
            'online_price' => (float) ($product->online_selling_price ?: $product->selling_price),
            'category' => $product->category?->name ?? 'Umum',
            'unit' => $product->unit,
            'step' => 1,
            'increment' => strtolower($product->unit) === 'gram' ? 50 : 1,
            'stock' => (float) ($product->dailyStocks->first()?->quantity ?? 0),
        ])->values();

        return $this->view('pos.index', compact('products', 'categories', 'members', 'pendingBills', 'pendingBillData', 'posProducts'));
    }

    private function validateOrder(Request $request): array
    {
        return $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.name' => ['nullable', 'string', 'max:120'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'member_id' => ['nullable', 'integer'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', Rule::in(['amount', 'percent', 'member'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(['cash', 'transfer', 'qris', 'deposit'])],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required_with:payments', Rule::in(['cash', 'transfer', 'qris', 'debit', 'deposit'])],
            'payments.*.provider' => ['nullable', 'string', 'max:80'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0'],
            'service_type' => ['required', Rule::in(['dine_in', 'takeaway', 'online'])],
            'table_number' => ['nullable', 'string', 'max:20', Rule::requiredIf(fn () => $request->input('service_type') === 'dine_in')],
            'online_platform' => ['nullable', 'string', 'max:30', Rule::requiredIf(fn () => $request->input('service_type') === 'online')],
            'notes' => ['nullable', 'string', 'max:500'],
            'pending_transaction_id' => ['nullable', 'integer'],
            'transaction_type' => ['nullable', Rule::in(['sale', 'replacement'])],
            'approval_pin' => ['nullable', 'string', 'min:4', 'max:12'],
        ]);
    }

    private function orderLines(array $data, bool $checkStock = true)
    {
        $allowCustom = (bool) $this->activeStoreRecord()->allow_custom_amount;

        return collect($data['items'])->map(function ($line) use ($data, $checkStock, $allowCustom) {
            if (empty($line['id'])) {
                abort_unless($allowCustom && ! empty($line['name']) && isset($line['price']), 422, 'Custom amount sedang nonaktif atau datanya belum lengkap.');

                return [
                    'product' => null, 'stock' => null, 'qty' => (float) $line['qty'],
                    'name' => trim($line['name']), 'category' => 'Custom', 'price' => (float) $line['price'],
                    'cost' => 0, 'subtotal' => (float) $line['price'] * (float) $line['qty'], 'custom' => true,
                ];
            }

            $product = Product::with('category')->where('tenant_id', $this->tenantId())->where('product_type', 'menu')->lockForUpdate()->findOrFail($line['id']);
            $stock = DailyMenuStock::firstOrCreate(
                ['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'stock_date' => today()],
                ['quantity' => 0]
            );
            $stock->refresh();
            if ($checkStock) {
                abort_if($stock->quantity < $line['qty'], 422, "Stok {$product->name} tidak mencukupi.");
            }
            $price = $data['service_type'] === 'online' && (float) $product->online_selling_price > 0
                ? (float) $product->online_selling_price : (float) $product->selling_price;

            return [
                'product' => $product, 'stock' => $stock, 'qty' => (float) $line['qty'],
                'name' => $product->name, 'category' => $product->category?->name ?? 'Umum', 'price' => $price,
                'cost' => (float) $product->purchase_price, 'subtotal' => $price * (float) $line['qty'], 'custom' => false,
            ];
        });
    }

    private function discountFor(array $data, float $subtotal, ?Member $member): array
    {
        $type = $data['discount_type'] ?? 'amount';
        $value = (float) ($data['discount_value'] ?? $data['discount'] ?? 0);
        if ($type === 'member') {
            abort_unless($member, 422, 'Scan atau pilih member untuk diskon member.');
            $value = (float) $member->discount_percent;
        }
        if ($type === 'percent' || $type === 'member') {
            abort_if($value > 100, 422, 'Persentase diskon maksimal 100%.');
            $discount = $subtotal * $value / 100;
        } else {
            $discount = $value;
        }

        return [$type, $value, min($discount, $subtotal)];
    }

    private function normalizedPayments(array $data, float $total, ?Member $member): array
    {
        $payments = collect($data['payments'] ?? [
            ['method' => $data['payment_method'] ?? 'cash', 'provider' => null, 'amount' => ($data['payment_method'] ?? 'cash') === 'cash' ? ($data['paid_amount'] ?? 0) : $total],
        ])->filter(fn ($row) => (float) ($row['amount'] ?? 0) > 0)->map(fn ($row) => [
            'method' => $row['method'], 'provider' => trim((string) ($row['provider'] ?? '')) ?: null, 'amount' => (float) $row['amount'],
        ])->values();

        foreach ($payments as $payment) {
            if (in_array($payment['method'], ['transfer', 'qris', 'debit'])) {
                abort_unless($payment['provider'], 422, 'Bank/provider wajib dipilih untuk transfer, QRIS, atau debit.');
            }
        }
        $deposit = $payments->where('method', 'deposit')->sum('amount');
        if ($deposit > 0) {
            abort_unless($member, 422, 'Pilih member untuk menggunakan deposit.');
            abort_if($deposit > (float) $member->deposit_balance, 422, 'Saldo deposit member tidak mencukupi.');
        }
        $paid = $payments->sum('amount');
        abort_if($paid < $total, 422, 'Nominal pembayaran kurang.');
        abort_if($paid > $total && $payments->where('method', 'cash')->sum('amount') < ($paid - $total), 422, 'Kelebihan pembayaran hanya dapat berasal dari tunai.');

        return [$payments, $paid, $deposit];
    }

    public function checkout(Request $request)
    {
        $data = $this->validateOrder($request);
        $transaction = DB::transaction(function () use ($data) {
            $lines = $this->orderLines($data);
            $member = ! empty($data['member_id']) ? Member::where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($data['member_id']) : null;
            $subtotal = (float) $lines->sum('subtotal');
            [$discountType, $discountValue, $discount] = $this->discountFor($data, $subtotal, $member);
            $transactionType = $data['transaction_type'] ?? 'sale';
            $authorizer = null;
            if ($transactionType === 'replacement') {
                $authorizer = $this->supervisorByPin($data['approval_pin'] ?? null);
                abort_unless($authorizer, 422, 'PIN Manager/SPV tidak valid untuk retur pengganti.');
                $discount = $subtotal;
            }
            $total = $transactionType === 'replacement' ? 0 : $subtotal - $discount;
            [$payments, $paid, $depositUsed] = $total > 0 ? $this->normalizedPayments($data, $total, $member) : [collect(), 0, 0];
            $primary = $payments->firstWhere('method', '!=', 'deposit')['method'] ?? ($payments->first()['method'] ?? 'cash');
            $primary = $primary === 'debit' ? 'transfer' : $primary;
            $invoice = 'TRX-'.now()->format('ymd-His').'-'.strtoupper(Str::random(3));

            $trx = null;
            if (! empty($data['pending_transaction_id'])) {
                $trx = Transaction::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->where('status', 'pending')->lockForUpdate()->findOrFail($data['pending_transaction_id']);
                $trx->items()->delete();
                $trx->payments()->delete();
            }
            $attributes = [
                'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(),
                'member_id' => $member?->id, 'invoice_no' => $trx?->invoice_no ?? $invoice, 'report_type' => 'real',
                'status' => 'completed', 'transaction_type' => $transactionType,
                'service_type' => $data['service_type'], 'table_number' => $data['service_type'] === 'dine_in' ? $data['table_number'] : null,
                'online_platform' => $data['service_type'] === 'online' ? $data['online_platform'] : null,
                'subtotal' => $subtotal, 'discount_type' => $discountType, 'discount_value' => $discountValue,
                'discount' => $discount, 'total' => $total, 'payment_method' => $primary, 'paid_amount' => $paid,
                'change_amount' => max(0, $paid - $total), 'notes' => $data['notes'] ?? null, 'transacted_at' => now(),
                'void_authorized_by' => $transactionType === 'replacement' ? $authorizer?->id : null,
            ];
            $trx ? $trx->update($attributes) : $trx = Transaction::create($attributes);

            foreach ($lines as $line) {
                $trx->items()->create([
                    'product_id' => $line['product']?->id, 'product_name' => $line['name'], 'category_name' => $line['category'],
                    'is_custom' => $line['custom'], 'quantity' => $line['qty'], 'price' => $line['price'],
                    'cost' => $line['cost'], 'subtotal' => $line['subtotal'],
                ]);
                if ($line['stock']) {
                    $line['stock']->decrement('quantity', $line['qty']);
                    DB::table('stock_movements')->insert([
                        'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $line['product']->id,
                        'user_id' => auth()->id(), 'type' => 'sale', 'activity' => $transactionType === 'replacement' ? 'replacement' : 'sale',
                        'quantity' => -$line['qty'], 'reference' => $trx->invoice_no, 'notes' => $transactionType === 'replacement' ? 'Retur/pengganti' : 'Penjualan POS',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            foreach ($payments as $payment) {
                $trx->payments()->create($payment);
            }
            if ($depositUsed > 0 && $member) {
                $member->decrement('deposit_balance', $depositUsed);
                DB::table('deposit_transactions')->insert([
                    'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'member_id' => $member->id,
                    'user_id' => auth()->id(), 'transaction_id' => $trx->id, 'type' => 'debit', 'payment_method' => 'deposit',
                    'amount' => $depositUsed, 'balance_after' => $member->fresh()->deposit_balance,
                    'description' => "Pembayaran {$trx->invoice_no}", 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $trx;
        });

        return response()->json(['ok' => true, 'invoice' => $transaction->invoice_no, 'print_url' => route('transactions.print', $transaction)]);
    }

    public function holdBill(Request $request)
    {
        $data = $this->validateOrder($request);
        $transaction = DB::transaction(function () use ($data) {
            $lines = $this->orderLines($data, false);
            $member = ! empty($data['member_id']) ? Member::where('tenant_id', $this->tenantId())->findOrFail($data['member_id']) : null;
            $subtotal = (float) $lines->sum('subtotal');
            [$discountType, $discountValue, $discount] = $this->discountFor($data, $subtotal, $member);
            $trx = Transaction::create([
                'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(), 'member_id' => $member?->id,
                'invoice_no' => 'BILL-'.now()->format('ymd-His').'-'.strtoupper(Str::random(2)), 'status' => 'pending', 'transaction_type' => 'sale',
                'report_type' => 'real', 'service_type' => $data['service_type'], 'table_number' => $data['table_number'] ?? null,
                'online_platform' => $data['online_platform'] ?? null, 'subtotal' => $subtotal, 'discount_type' => $discountType,
                'discount_value' => $discountValue, 'discount' => $discount, 'total' => $subtotal - $discount,
                'payment_method' => 'cash', 'paid_amount' => 0, 'change_amount' => 0, 'notes' => $data['notes'] ?? null, 'transacted_at' => now(),
            ]);
            foreach ($lines as $line) {
                $trx->items()->create([
                    'product_id' => $line['product']?->id, 'product_name' => $line['name'], 'category_name' => $line['category'],
                    'is_custom' => $line['custom'], 'quantity' => $line['qty'], 'price' => $line['price'], 'cost' => $line['cost'], 'subtotal' => $line['subtotal'],
                ]);
            }

            return $trx;
        });

        return response()->json(['ok' => true, 'invoice' => $transaction->invoice_no]);
    }

    public function toggleCustomAmount(Request $request)
    {
        abort_unless(auth()->user()->isSupervisor(), 403);
        $this->activeStoreRecord()->update(['allow_custom_amount' => $request->boolean('enabled')]);

        return back()->with('success', 'Custom amount '.($request->boolean('enabled') ? 'diaktifkan.' : 'dinonaktifkan.'));
    }

    public function closeCashier()
    {
        $sales = DB::table('transaction_items')->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->leftJoin('products', 'products.id', '=', 'transaction_items.product_id')
            ->where('transactions.tenant_id', $this->tenantId())->where('transactions.store_id', $this->storeId())
            ->where('transactions.status', 'completed')->where('transactions.transaction_type', 'sale')
            ->whereDate('transactions.transacted_at', today())->select('transaction_items.product_name', DB::raw("COALESCE(products.unit, 'item') as unit"), DB::raw('SUM(transaction_items.quantity) as quantity'))
            ->groupBy('transaction_items.product_name', 'products.unit')->orderBy('transaction_items.product_name')->get();

        return view('pos.close', ['sales' => $sales, 'store' => $this->activeStoreRecord(), 'tenant' => auth()->user()->tenant]);
    }

    public function products()
    {
        $products = Product::with('category')
            ->withSum(['stocks as warehouse_stock' => fn ($q) => $q->where('store_id', $this->storeId())], 'quantity')
            ->withSum(['dailyStocks as daily_stock' => fn ($q) => $q->where('store_id', $this->storeId())->whereDate('stock_date', today())], 'quantity')
            ->where('tenant_id', $this->tenantId())->latest()->paginate(20);
        $categories = Category::where('tenant_id', $this->tenantId())->orderBy('name')->get();

        return $this->view('products.index', compact('products', 'categories'));
    }

    private function productData(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'sku' => ['required', 'string', 'max:50', Rule::unique('products')->where('tenant_id', $this->tenantId())->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('products')->where('tenant_id', $this->tenantId())->ignore($product?->id)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
            'unit' => 'required|string|max:20', 'purchase_price' => 'required|numeric|min:0', 'selling_price' => 'required|numeric|min:0',
            'online_selling_price' => 'nullable|numeric|min:0', 'minimum_stock' => 'required|integer|min:0',
            'product_type' => [$product ? 'nullable' : 'required', Rule::in(['menu', 'ingredient'])], 'initial_stock' => 'nullable|numeric|min:0',
        ]);
    }

    public function storeProduct(Request $request)
    {
        $data = $this->productData($request);
        $initial = $data['initial_stock'] ?? 0;
        unset($data['initial_stock']);
        $data['online_selling_price'] = $data['online_selling_price'] ?? $data['selling_price'];
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
        $data = $this->productData($request, $product);
        unset($data['initial_stock'], $data['product_type']);
        $data['online_selling_price'] = $data['online_selling_price'] ?? $data['selling_price'];
        $product->update($data);

        return back()->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroyProduct(Product $product)
    {
        abort_unless($product->tenant_id === $this->tenantId(), 404);
        $product->delete();

        return back()->with('success', 'Produk dipindahkan ke arsip.');
    }

    public function exportProducts()
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->setTitle('Produk');
        $sheet->fromArray(['SKU', 'Barcode', 'Nama', 'Jenis', 'Kategori', 'Satuan', 'Harga Beli', 'Harga Normal', 'Harga Online', 'Stok Minimum'], null, 'A1');
        $row = 2;
        Product::with('category')->where('tenant_id', $this->tenantId())->orderBy('name')->each(function ($product) use ($sheet, &$row) {
            $sheet->fromArray([[$product->sku, $product->barcode, $product->name, $product->product_type, $product->category?->name, $product->unit, (float) $product->purchase_price, (float) $product->selling_price, (float) $product->online_selling_price, $product->minimum_stock]], null, 'A'.$row++);
        });
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(fn () => (new Xlsx($sheet->getParent()))->save('php://output'), 'produk-'.now()->format('Ymd').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function importProducts(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $rows = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        $headers = collect(array_shift($rows))->map(fn ($value) => Str::slug((string) $value, '_'));
        $aliases = [
            'jenis' => 'product_type', 'nama' => 'name', 'kategori' => 'category', 'satuan' => 'unit',
            'harga_beli' => 'purchase_price', 'harga_normal' => 'selling_price', 'harga_jual' => 'selling_price',
            'harga_online' => 'online_selling_price', 'stok_minimum' => 'minimum_stock', 'stok_awal' => 'initial_stock',
        ];
        $count = 0;
        DB::transaction(function () use ($rows, $headers, $aliases, &$count) {
            foreach ($rows as $row) {
                $record = $headers->combine(array_pad($row, $headers->count(), null))->mapWithKeys(fn ($value, $key) => [$aliases[$key] ?? $key => $value]);
                if (! $record->get('sku') || ! $record->get('name')) {
                    continue;
                }
                $categoryId = null;
                if ($record->get('category')) {
                    $categoryId = Category::firstOrCreate(['tenant_id' => $this->tenantId(), 'name' => trim($record->get('category'))], ['color' => '#78978a'])->id;
                }
                $product = Product::withTrashed()->updateOrCreate(
                    ['tenant_id' => $this->tenantId(), 'sku' => trim($record->get('sku'))],
                    ['name' => trim($record->get('name')), 'barcode' => $record->get('barcode') ?: null, 'category_id' => $categoryId, 'product_type' => $record->get('product_type') === 'ingredient' ? 'ingredient' : 'menu',
                        'unit' => $record->get('unit') ?: 'pcs', 'purchase_price' => (float) ($record->get('purchase_price') ?: 0),
                        'selling_price' => (float) ($record->get('selling_price') ?: 0), 'online_selling_price' => (float) ($record->get('online_selling_price') ?: $record->get('selling_price') ?: 0),
                        'minimum_stock' => (int) ($record->get('minimum_stock') ?: 0), 'is_active' => true, 'deleted_at' => null]
                );
                $initial = (float) ($record->get('initial_stock') ?: 0);
                if ($product->product_type === 'menu') {
                    DailyMenuStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'stock_date' => today()], ['quantity' => $initial]);
                } else {
                    ProductStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id], ['quantity' => $initial]);
                }
                $count++;
            }
        });

        return back()->with('success', $count.' produk berhasil diimpor/diperbarui.');
    }

    public function inventory()
    {
        Product::where('tenant_id', $this->tenantId())->where('is_active', true)->each(function ($product) {
            $product->product_type === 'menu'
                ? DailyMenuStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'stock_date' => today()], ['quantity' => 0])
                : ProductStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id], ['quantity' => 0]);
        });
        $movementsToday = DB::table('stock_movements')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->whereDate('created_at', today())->get()->groupBy('product_id');
        $counts = StockCount::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->whereDate('count_date', today())->get()->keyBy('product_id');
        $ingredientStocks = ProductStock::with('product.category')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())
            ->whereHas('product', fn ($q) => $q->where('product_type', 'ingredient'))->orderBy('quantity')->get()->map(function ($stock) use ($movementsToday) {
                $moves = $movementsToday->get($stock->product_id, collect());
                $stock->opening = $stock->quantity - $moves->sum('quantity');
                $stock->incoming = $moves->where('activity', '!=', 'production')->where('quantity', '>', 0)->sum('quantity');
                $stock->outgoing = abs($moves->whereNotIn('activity', ['production'])->where('quantity', '<', 0)->sum('quantity'));
                $stock->processed = abs($moves->where('activity', 'production')->where('quantity', '<', 0)->sum('quantity'));
                return $stock;
            });
        $menuStocks = DailyMenuStock::with('product.category')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->whereDate('stock_date', today())
            ->whereHas('product', fn ($q) => $q->where('product_type', 'menu'))->orderBy('quantity')->get()->map(function ($stock) use ($movementsToday, $counts) {
                $moves = $movementsToday->get($stock->product_id, collect());
                $stock->opening = $stock->quantity - $moves->sum('quantity');
                $stock->produced = $moves->where('activity', 'production')->where('quantity', '>', 0)->sum('quantity');
                $stock->sold = abs($moves->where('activity', 'sale')->sum('quantity'));
                $stock->consumption = abs($moves->where('activity', 'consumption')->sum('quantity'));
                $stock->count = $counts->get($stock->product_id);
                return $stock;
            });
        $inventoryProducts = Product::where('tenant_id', $this->tenantId())->where('is_active', true)->orderBy('product_type')->orderBy('name')->get();
        $movements = DB::table('stock_movements')->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.tenant_id', $this->tenantId())->where('stock_movements.store_id', $this->storeId())
            ->select('stock_movements.*', 'products.name as product_name')->latest('stock_movements.created_at')->take(12)->get();
        $productions = StockProduction::with(['ingredient', 'menu'])->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->latest()->take(10)->get();

        return $this->view('inventory.index', compact('ingredientStocks', 'menuStocks', 'inventoryProducts', 'movements', 'productions'));
    }

    public function adjustStock(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer', 'type' => ['required', Rule::in(['adjustment_in', 'adjustment_out', 'consumption'])], 'quantity' => 'required|numeric|min:0.001', 'notes' => 'required|string|max:255']);
        $product = Product::where('tenant_id', $this->tenantId())->findOrFail($data['product_id']);
        $stock = $product->product_type === 'menu'
            ? DailyMenuStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'stock_date' => today()], ['quantity' => 0])
            : ProductStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id], ['quantity' => 0]);
        $positive = $data['type'] === 'adjustment_in';
        $delta = $positive ? $data['quantity'] : -$data['quantity'];
        abort_if($stock->quantity + $delta < 0, 422, 'Stok tidak boleh menjadi negatif.');
        $stock->increment('quantity', $delta);
        DB::table('stock_movements')->insert([
            'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'user_id' => auth()->id(),
            'type' => $positive ? 'adjustment_in' : 'adjustment_out', 'activity' => $data['type'] === 'consumption' ? 'consumption' : 'adjustment',
            'quantity' => $delta, 'notes' => $data['notes'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Stok berhasil disesuaikan.');
    }

    public function storeProduction(Request $request)
    {
        $data = $request->validate([
            'ingredient_product_id' => 'required|integer', 'menu_product_id' => 'required|integer|different:ingredient_product_id',
            'ingredient_quantity' => 'required|numeric|min:0.001', 'output_quantity' => 'required|numeric|min:0.001', 'notes' => 'nullable|string|max:255',
        ]);
        DB::transaction(function () use ($data) {
            $ingredient = Product::where('tenant_id', $this->tenantId())->where('product_type', 'ingredient')->findOrFail($data['ingredient_product_id']);
            $menu = Product::where('tenant_id', $this->tenantId())->where('product_type', 'menu')->findOrFail($data['menu_product_id']);
            $rawStock = ProductStock::where('store_id', $this->storeId())->where('product_id', $ingredient->id)->lockForUpdate()->firstOrFail();
            abort_if($rawStock->quantity < $data['ingredient_quantity'], 422, 'Stok bahan baku tidak mencukupi untuk produksi.');
            $menuStock = DailyMenuStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $menu->id, 'stock_date' => today()], ['quantity' => 0]);
            $rawStock->decrement('quantity', $data['ingredient_quantity']);
            $menuStock->increment('quantity', $data['output_quantity']);
            $production = StockProduction::create($data + ['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(), 'production_date' => today()]);
            foreach ([[$ingredient, -$data['ingredient_quantity'], 'Bahan baku terolah'], [$menu, $data['output_quantity'], 'Tambahan olahan']] as [$product, $quantity, $notes]) {
                DB::table('stock_movements')->insert(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'user_id' => auth()->id(), 'type' => $quantity > 0 ? 'adjustment_in' : 'adjustment_out', 'activity' => 'production', 'quantity' => $quantity, 'reference' => 'PROD-'.$production->id, 'notes' => $notes, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return back()->with('success', 'Produksi tercatat; bahan baku dan stok olahan otomatis terhubung.');
    }

    public function storeStockCount(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer', 'actual_quantity' => 'required|numeric|min:0', 'notes' => 'nullable|string|max:255']);
        $product = Product::where('tenant_id', $this->tenantId())->findOrFail($data['product_id']);
        $stock = $product->product_type === 'menu'
            ? DailyMenuStock::where('store_id', $this->storeId())->where('product_id', $product->id)->whereDate('stock_date', today())->firstOrFail()
            : ProductStock::where('store_id', $this->storeId())->where('product_id', $product->id)->firstOrFail();
        StockCount::updateOrCreate(
            ['store_id' => $this->storeId(), 'product_id' => $product->id, 'count_date' => today()],
            ['tenant_id' => $this->tenantId(), 'user_id' => auth()->id(), 'expected_quantity' => $stock->quantity, 'actual_quantity' => $data['actual_quantity'], 'notes' => $data['notes'] ?? null]
        );

        return back()->with('success', 'Stock opname tersimpan. Selisih sistem dan fisik kini terlihat.');
    }

    public function purchases()
    {
        $purchases = Purchase::with('items')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->latest('purchased_at')->paginate(20);
        $products = Product::where('tenant_id', $this->tenantId())->where('product_type', 'ingredient')->orderBy('name')->get();

        return $this->view('purchases.index', compact('purchases', 'products'));
    }

    public function storePurchase(Request $request)
    {
        $data = $request->validate([
            'supplier_name' => 'required|string|max:120', 'product_id' => 'required|integer', 'quantity' => 'required|numeric|min:0.001',
            'unit_cost' => 'required|numeric|min:0', 'purchased_at' => 'required|date', 'status' => ['required', Rule::in(['received', 'not_received'])],
            'payment_status' => ['required', Rule::in(['paid', 'dp', 'unpaid'])], 'dp_amount' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:255',
        ]);
        $product = Product::where('tenant_id', $this->tenantId())->where('product_type', 'ingredient')->findOrFail($data['product_id']);
        $total = $data['quantity'] * $data['unit_cost'];
        abort_if(($data['dp_amount'] ?? 0) > $total, 422, 'DP tidak boleh melebihi total pembelian.');
        DB::transaction(function () use ($data, $product, $total) {
            $received = $data['status'] === 'received';
            $purchase = Purchase::create([
                'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(),
                'purchase_no' => 'PO-'.now()->format('ymd-His').'-'.strtoupper(Str::random(2)), 'supplier_name' => $data['supplier_name'],
                'total' => $total, 'status' => $received ? 'received' : 'draft', 'payment_status' => $data['payment_status'],
                'dp_amount' => $data['payment_status'] === 'dp' ? ($data['dp_amount'] ?? 0) : ($data['payment_status'] === 'paid' ? $total : 0),
                'received_at' => $received ? now() : null, 'purchased_at' => $data['purchased_at'], 'notes' => $data['notes'] ?? null,
            ]);
            $purchase->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $data['quantity'], 'unit_cost' => $data['unit_cost'], 'subtotal' => $total]);
            if ($received) {
                $this->applyPurchaseStock($purchase, 1);
            }
        });

        return back()->with('success', 'Pembelian berhasil dicatat.');
    }

    private function applyPurchaseStock(Purchase $purchase, int $direction): void
    {
        foreach ($purchase->items as $item) {
            $stock = ProductStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $purchase->store_id, 'product_id' => $item->product_id], ['quantity' => 0]);
            $delta = $direction * $item->quantity;
            abort_if($stock->quantity + $delta < 0, 422, 'Status tidak dapat diubah karena stok sudah terpakai.');
            $stock->increment('quantity', $delta);
            DB::table('stock_movements')->insert(['tenant_id' => $this->tenantId(), 'store_id' => $purchase->store_id, 'product_id' => $item->product_id, 'user_id' => auth()->id(), 'type' => $direction > 0 ? 'purchase' : 'adjustment_out', 'activity' => $direction > 0 ? 'purchase' : 'purchase_reversal', 'quantity' => $delta, 'reference' => $purchase->purchase_no, 'notes' => $direction > 0 ? 'Penerimaan pembelian' : 'Pembelian belum diterima', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function updatePurchaseStatus(Request $request, Purchase $purchase)
    {
        abort_unless($purchase->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($purchase->store_id);
        $data = $request->validate(['status' => ['required', Rule::in(['received', 'not_received'])], 'payment_status' => ['required', Rule::in(['paid', 'dp', 'unpaid'])], 'dp_amount' => 'nullable|numeric|min:0']);
        abort_if(($data['dp_amount'] ?? 0) > $purchase->total, 422, 'DP tidak boleh melebihi total pembelian.');
        DB::transaction(function () use ($purchase, $data) {
            if ($data['status'] === 'received' && ! $purchase->received_at) {
                $this->applyPurchaseStock($purchase, 1);
                $purchase->received_at = now();
            } elseif ($data['status'] === 'not_received' && $purchase->received_at) {
                $this->applyPurchaseStock($purchase, -1);
                $purchase->received_at = null;
            }
            $purchase->status = $data['status'] === 'received' ? 'received' : 'draft';
            $purchase->payment_status = $data['payment_status'];
            $purchase->dp_amount = $data['payment_status'] === 'dp' ? ($data['dp_amount'] ?? 0) : ($data['payment_status'] === 'paid' ? $purchase->total : 0);
            $purchase->save();
        });

        return back()->with('success', 'Status pembelian diperbarui.');
    }

    public function expenses()
    {
        $expenses = Expense::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->latest('expense_date')->paginate(20);
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
        $this->assertStoreAccess($expense->store_id);
        $expense->delete();
        return back()->with('success', 'Pengeluaran dipindahkan ke arsip.');
    }

    public function members(Request $request)
    {
        $search = trim($request->string('q')->toString());
        $members = Member::where('tenant_id', $this->tenantId())->when($search, fn ($q) => $q->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('member_code', 'like', "%{$search}%")->orWhere('qr_code', $search)))->latest()->paginate(20)->withQueryString();
        $availableCards = MemberCard::where('tenant_id', $this->tenantId())->where('status', 'available')->orderBy('member_code')->get();
        $memberSummary = null;
        if (auth()->user()->role === User::SUPERADMIN) {
            $memberSummary = ['count' => Member::where('tenant_id', $this->tenantId())->count(), 'deposit' => Member::where('tenant_id', $this->tenantId())->sum('deposit_balance')];
        }
        return $this->view('members.index', compact('members', 'availableCards', 'memberSummary', 'search'));
    }

    public function storeMember(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:120', 'domicile' => 'nullable|string|max:120', 'birth_date' => 'nullable|date', 'discount_percent' => 'nullable|numeric|min:0|max:100', 'member_card_id' => 'required|integer']);
        $member = DB::transaction(function () use ($data) {
            $card = MemberCard::where('tenant_id', $this->tenantId())->where('status', 'available')->lockForUpdate()->findOrFail($data['member_card_id']);
            unset($data['member_card_id']);
            $data['discount_percent'] = $data['discount_percent'] ?? (float) $this->activeStoreRecord()->member_discount_percent;
            $member = Member::create($data + ['tenant_id' => $this->tenantId(), 'member_code' => $card->member_code, 'qr_code' => $card->qr_code, 'is_active' => true]);
            $card->update(['member_id' => $member->id, 'status' => 'assigned']);

            return $member;
        });

        return back()->with('success', 'Kartu '.$member->member_code.' aktif dan data member berhasil disimpan.');
    }

    public function topup(Request $request, Member $member)
    {
        abort_unless($member->tenant_id === $this->tenantId(), 404);
        $data = $request->validate(['amount' => 'required|numeric|min:1000', 'payment_method' => ['nullable', Rule::in(['cash', 'transfer'])]]);
        DB::transaction(function () use ($member, $data) {
            $locked = Member::where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($member->id);
            $locked->increment('deposit_balance', $data['amount']);
            DB::table('deposit_transactions')->insert(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'member_id' => $locked->id, 'user_id' => auth()->id(), 'transaction_id' => null, 'type' => 'credit', 'payment_method' => $data['payment_method'] ?? 'cash', 'amount' => $data['amount'], 'balance_after' => $locked->fresh()->deposit_balance, 'description' => 'Top up deposit', 'created_at' => now(), 'updated_at' => now()]);
        });
        return back()->with('success', 'Deposit berhasil ditambahkan.');
    }

    public function findMember(string $code)
    {
        $member = Member::where('tenant_id', $this->tenantId())->where(fn ($q) => $q->where('qr_code', $code)->orWhere('member_code', $code))->firstOrFail();
        return response()->json($member->only('id', 'name', 'member_code', 'deposit_balance', 'discount_percent'));
    }

    public function findAvailableMemberCard(string $code)
    {
        $card = MemberCard::where('tenant_id', $this->tenantId())->where('status', 'available')
            ->where(fn ($query) => $query->where('qr_code', $code)->orWhere('member_code', $code))->firstOrFail();

        return response()->json(['id' => $card->id, 'member_code' => $card->member_code, 'qr_code' => $card->qr_code]);
    }

    public function transactions(Request $request)
    {
        $transactions = Transaction::with(['member', 'user', 'payments', 'voidAuthorizer'])->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))->latest('transacted_at')->paginate(20)->withQueryString();
        return $this->view('transactions.index', compact('transactions'));
    }

    public function print(Transaction $transaction)
    {
        abort_unless($transaction->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($transaction->store_id);
        $transaction->load(['items.product', 'member', 'user', 'store', 'payments']);
        return view('transactions.print', ['transaction' => $transaction, 'tenant' => auth()->user()->tenant, 'receiptStore' => $transaction->store]);
    }

    public function destroyTransaction(Request $request, Transaction $transaction)
    {
        abort_unless($transaction->tenant_id === $this->tenantId() && $transaction->store_id === $this->storeId(), 404);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255', 'approval_pin' => 'nullable|string|max:12']);
        abort_unless($transaction->status === 'completed', 422, 'Hanya transaksi selesai yang dapat dibatalkan.');
        $needsApproval = $transaction->transacted_at->diffInSeconds(now()) > 30;
        $authorizer = $needsApproval ? $this->supervisorByPin($data['approval_pin'] ?? null) : auth()->user();
        abort_if($needsApproval && ! $authorizer, 422, 'Transaksi lebih dari 30 detik memerlukan PIN Manager/SPV yang valid.');

        DB::transaction(function () use ($transaction, $data, $authorizer) {
            $transaction->load(['items', 'payments', 'member']);
            foreach ($transaction->items as $item) {
                if (! $item->product_id) continue;
                $stock = DailyMenuStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $transaction->store_id, 'product_id' => $item->product_id, 'stock_date' => $transaction->transacted_at->toDateString()], ['quantity' => 0]);
                $stock->increment('quantity', $item->quantity);
                DB::table('stock_movements')->insert(['tenant_id' => $this->tenantId(), 'store_id' => $transaction->store_id, 'product_id' => $item->product_id, 'user_id' => auth()->id(), 'type' => 'adjustment_in', 'activity' => 'void_reversal', 'quantity' => $item->quantity, 'reference' => $transaction->invoice_no, 'notes' => 'Pembatalan: '.$data['reason'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $deposit = $transaction->payments->where('method', 'deposit')->sum('amount');
            if ($deposit <= 0 && $transaction->payment_method === 'deposit') $deposit = $transaction->total;
            if ($deposit > 0 && $transaction->member) {
                $transaction->member->increment('deposit_balance', $deposit);
                DB::table('deposit_transactions')->insert(['tenant_id' => $this->tenantId(), 'store_id' => $transaction->store_id, 'member_id' => $transaction->member->id, 'user_id' => auth()->id(), 'transaction_id' => $transaction->id, 'type' => 'credit', 'payment_method' => 'deposit', 'amount' => $deposit, 'balance_after' => $transaction->member->fresh()->deposit_balance, 'description' => 'Refund pembatalan '.$transaction->invoice_no, 'created_at' => now(), 'updated_at' => now()]);
            }
            $transaction->update(['status' => 'voided', 'cancel_reason' => $data['reason'], 'void_authorized_by' => $authorizer?->id, 'voided_at' => now()]);
        });

        return back()->with('success', 'Transaksi dibatalkan, stok dan deposit terkait telah dipulihkan.');
    }

    public function reports(Request $request, TransactionReportExporter $exporter)
    {
        $canSeeNonReal = auth()->user()->canSeeNonRealReport();
        $type = $canSeeNonReal && $request->get('type') === 'non_real' ? 'non_real' : 'real';
        $percentage = (float) $this->activeStoreRecord()->non_real_percentage;
        $factor = $this->reportFactor($type);
        [$period, $from, $to] = $this->reportRange($request);
        $storeId = $this->isConsolidated() ? null : $this->storeId();
        $data = $exporter->data($this->tenantId(), $storeId, $from, $to, $factor);
        ['sales' => $sales, 'cost' => $cost, 'expenses' => $expenses, 'profit' => $profit, 'daily' => $daily, 'payments' => $payments, 'transactions' => $transactionRows, 'products' => $productSales, 'newMembers' => $newMembers, 'topups' => $topups, 'depositUsed' => $depositUsed, 'turnoverNetDeposit' => $turnoverNetDeposit, 'storeComparison' => $storeComparison] = $data;

        return $this->view('reports.index', compact('type', 'factor', 'percentage', 'period', 'from', 'to', 'sales', 'cost', 'expenses', 'profit', 'daily', 'payments', 'transactionRows', 'productSales', 'newMembers', 'topups', 'depositUsed', 'turnoverNetDeposit', 'storeComparison', 'canSeeNonReal'));
    }

    public function exportReport(Request $request, TransactionReportExporter $exporter)
    {
        $canSeeNonReal = auth()->user()->canSeeNonRealReport();
        $type = $canSeeNonReal && $request->get('type') === 'non_real' ? 'non_real' : 'real';
        $factor = $this->reportFactor($type);
        [, $from, $to] = $this->reportRange($request);
        $store = $this->isConsolidated() ? new Store(['name' => 'Consolidated Semua Warung']) : Store::where('tenant_id', $this->tenantId())->findOrFail($this->storeId());
        $data = $exporter->data($this->tenantId(), $this->isConsolidated() ? null : $this->storeId(), $from, $to, $factor);
        $spreadsheet = $exporter->workbook($data, auth()->user()->tenant, $store, $from, $to, $type, $factor);
        $filename = 'laporan-transaksi-'.str_replace('_', '-', $type).'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xlsx';
        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, no-cache']);
    }

    private function roles()
    {
        return Role::where('tenant_id', $this->tenantId())->orderBy('position')->orderBy('name')->get();
    }

    public function settings()
    {
        $users = User::where('tenant_id', $this->tenantId())->orderBy('name')->get();
        $roles = $this->roles();
        $userCountPerRole = User::where('tenant_id', $this->tenantId())->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');
        // Superadmin adalah satu-satunya otoritas yang membuat akun, dan tidak bisa menugaskan role sistem.
        $creatableRoles = auth()->user()->role === User::SUPERADMIN
            ? $roles->where('is_system', false)->pluck('name', 'key')
            : collect();
        $settingsStore = $this->activeStoreRecord();
        $devices = ConnectedDevice::where('tenant_id', $this->tenantId())->with('store')
            ->where(fn ($query) => $query->whereNull('store_id')->orWhere('store_id', $settingsStore->id))
            ->get();
        $cards = MemberCard::where('tenant_id', $this->tenantId())->where('status', 'available')->latest()->take(20)->get();

        return $this->view('settings.index', ['tenant' => auth()->user()->tenant, 'settingsStore' => $settingsStore, 'stores' => $this->stores(), 'users' => $users, 'roles' => $roles, 'userCountPerRole' => $userCountPerRole, 'creatableRoles' => $creatableRoles, 'canManageRoles' => auth()->user()->role === User::SUPERADMIN, 'devices' => $devices, 'cards' => $cards]);
    }

    private function roleData(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'summary' => ['nullable', 'string', 'max:120'],
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(array_keys(Role::MODULES))],
            'can_see_non_real' => ['nullable', 'boolean'],
            'is_supervisor' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => $data['name'],
            'summary' => $data['summary'] ?? null,
            'modules' => Role::sanitizeModules($data['modules']),
            'can_see_non_real' => $request->boolean('can_see_non_real'),
            'is_supervisor' => $request->boolean('is_supervisor'),
            // Akses lintas warung tidak dibuka lewat form; lihat catatan pada view Pengaturan.
            'can_access_all_stores' => $role?->can_access_all_stores ?? false,
        ];
    }

    public function storeRole(Request $request)
    {
        abort_unless(auth()->user()->role === User::SUPERADMIN, 403);
        $key = $request->validate([
            'key' => ['required', 'string', 'max:30', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles')->where('tenant_id', $this->tenantId())],
        ])['key'];
        Role::create($this->roleData($request) + [
            'tenant_id' => $this->tenantId(),
            'key' => $key,
            'is_system' => false,
            'position' => (int) Role::where('tenant_id', $this->tenantId())->max('position') + 1,
        ]);

        return back()->with('success', 'Role baru berhasil ditambahkan.');
    }

    public function updateRole(Request $request, Role $role)
    {
        abort_unless(auth()->user()->role === User::SUPERADMIN, 403);
        abort_unless($role->tenant_id === $this->tenantId(), 404);
        abort_if($role->is_system, 422, 'Hak akses role sistem tidak dapat diubah.');
        $role->update($this->roleData($request, $role));
        auth()->user()->forgetRoleDefinition();

        return back()->with('success', 'Hak akses '.$role->name.' berhasil diperbarui.');
    }

    public function destroyRole(Role $role)
    {
        abort_unless(auth()->user()->role === User::SUPERADMIN, 403);
        abort_unless($role->tenant_id === $this->tenantId(), 404);
        abort_if($role->is_system, 422, 'Role sistem tidak dapat dihapus.');
        abort_if($role->users()->exists(), 422, 'Role masih dipakai akun aktif. Pindahkan akunnya terlebih dahulu.');
        $role->delete();

        return back()->with('success', 'Role '.$role->name.' dihapus.');
    }

    public function updateBrand(Request $request)
    {
        $store = $this->requestedSettingsStore($request);
        $data = $request->validate(['business_name' => 'required|string|max:120', 'logo' => 'nullable|image|max:2048']);
        if ($request->hasFile('logo')) $data['logo_path'] = $request->file('logo')->store('branding', 'public');
        unset($data['logo']);
        $store->update($data);
        return back()->with('success', 'Identitas '.$store->name.' berhasil diperbarui.');
    }

    public function updateReceiptSettings(Request $request)
    {
        $store = $this->requestedSettingsStore($request);
        $data = $request->validate(['receipt_header' => 'nullable|string|max:120', 'receipt_footer' => 'nullable|string|max:180']);
        $data['receipt_show_logo'] = $request->boolean('receipt_show_logo');
        $data['receipt_sort_by_category'] = $request->boolean('receipt_sort_by_category');
        $store->update($data);
        return back()->with('success', 'Tampilan struk '.$store->name.' berhasil diperbarui.');
    }

    public function updateBusinessRules(Request $request)
    {
        $store = $this->requestedSettingsStore($request);
        $data = $request->validate([
            'non_real_percentage' => 'required|numeric|min:0|max:100',
            'member_discount_percent' => 'required|numeric|min:0|max:100',
        ]);
        $store->update($data);

        return back()->with('success', 'Aturan laporan dan membership '.$store->name.' berhasil diperbarui.');
    }

    public function storeBranch(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'code' => ['required', 'string', 'max:20', Rule::unique('stores')->where('tenant_id', $this->tenantId())], 'address' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:30']);
        $source = $this->activeStoreRecord();
        Store::create($data + [
            'tenant_id' => $this->tenantId(),
            'is_active' => true,
            'business_name' => $source->business_name,
            'logo_path' => $source->logo_path,
            'allow_custom_amount' => $source->allow_custom_amount,
            'non_real_percentage' => $source->non_real_percentage,
            'member_discount_percent' => $source->member_discount_percent,
            'receipt_header' => $source->receipt_header,
            'receipt_footer' => $source->receipt_footer,
            'receipt_show_logo' => $source->receipt_show_logo,
            'receipt_sort_by_category' => $source->receipt_sort_by_category,
        ]);
        return back()->with('success', 'Cabang baru berhasil ditambahkan.');
    }

    public function storeDevice(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'type' => ['required', Rule::in(['receipt_printer', 'cash_drawer', 'barcode_scanner', 'customer_display', 'other'])], 'connection' => 'nullable|string|max:120', 'store_id' => 'nullable|integer']);
        if (! empty($data['store_id'])) {
            abort_unless(Store::where('tenant_id', $this->tenantId())->whereKey($data['store_id'])->exists(), 422);
            $this->assertStoreAccess((int) $data['store_id']);
        }
        ConnectedDevice::create($data + ['tenant_id' => $this->tenantId(), 'status' => 'active']);
        return back()->with('success', 'Perangkat berhasil ditambahkan.');
    }

    public function destroyDevice(ConnectedDevice $device)
    {
        abort_unless($device->tenant_id === $this->tenantId(), 404);
        $device->delete();
        return back()->with('success', 'Perangkat dilepas.');
    }

    public function storeUser(Request $request)
    {
        abort_unless(auth()->user()->role === User::SUPERADMIN, 403);
        // Role diambil dari master, kecuali role sistem yang tidak boleh ditugaskan ke akun baru.
        $assignable = Role::where('tenant_id', $this->tenantId())->where('is_system', false)->pluck('key')->all();
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|unique:users,email', 'role' => ['required', Rule::in($assignable)], 'store_id' => 'required|integer', 'password' => 'required|string|min:8', 'authorization_pin' => 'nullable|digits_between:4,8']);
        abort_unless(Store::where('tenant_id', $this->tenantId())->whereKey($data['store_id'])->exists(), 422);
        if (! empty($data['authorization_pin'])) $data['authorization_pin'] = Hash::make($data['authorization_pin']);
        User::create($data + ['tenant_id' => $this->tenantId(), 'password' => Hash::make($data['password']), 'is_active' => true]);
        return back()->with('success', 'Pengguna baru berhasil ditambahkan.');
    }

    public function destroyUser(User $user)
    {
        abort_unless(auth()->user()->role === User::SUPERADMIN && $user->tenant_id === $this->tenantId() && $user->id !== auth()->id(), 403);
        $user->delete();
        return back()->with('success', 'Akun pengguna dinonaktifkan.');
    }

    public function updateUserPin(Request $request, User $user)
    {
        abort_unless(auth()->user()->role === User::SUPERADMIN && $user->tenant_id === $this->tenantId(), 403);
        abort_unless($user->isSupervisor(), 422, 'PIN otorisasi hanya untuk Manager/SPV.');
        $pin = $request->validate(['authorization_pin' => 'required|digits_between:4,8'])['authorization_pin'];
        $user->update(['authorization_pin' => Hash::make($pin)]);

        return back()->with('success', 'PIN otorisasi '.$user->name.' berhasil diperbarui.');
    }

    public function precreateMemberCards(Request $request)
    {
        abort_unless(auth()->user()->role === User::SUPERADMIN, 403);
        $count = $request->validate(['count' => 'required|integer|min:1|max:100'])['count'];
        for ($i = 0; $i < $count; $i++) {
            $next = (MemberCard::where('tenant_id', $this->tenantId())->max('id') ?? 0) + 1;
            MemberCard::create(['tenant_id' => $this->tenantId(), 'member_code' => 'MBR-P'.str_pad($next, 5, '0', STR_PAD_LEFT), 'qr_code' => (string) Str::uuid(), 'status' => 'available']);
        }
        return back()->with('success', $count.' kartu QR siap dicetak dan diaktivasi saat customer mendaftar.');
    }

    public function runMaintenance(Request $request)
    {
        $data = $request->validate(['command' => ['required', Rule::in(['migrate', 'optimize_clear', 'storage_link'])]]);
        $commands = [
            'migrate' => ['command' => 'migrate', 'parameters' => ['--force' => true], 'label' => 'Migrasi database'],
            'optimize_clear' => ['command' => 'optimize:clear', 'parameters' => [], 'label' => 'Bersihkan cache'],
            'storage_link' => ['command' => 'storage:link', 'parameters' => [], 'label' => 'Hubungkan storage'],
        ];
        $selected = $commands[$data['command']];
        if ($data['command'] === 'storage_link' && File::exists(public_path('storage'))) return back()->with('success', 'Storage publik sudah terhubung.')->with('maintenance_output', 'Tautan public/storage sudah tersedia. Tidak ada perubahan yang diperlukan.');
        try {
            $exitCode = Artisan::call($selected['command'], $selected['parameters']);
            $output = trim(Artisan::output()) ?: 'Perintah selesai tanpa keluaran tambahan.';
            if ($exitCode !== 0) return back()->withErrors(['maintenance' => $selected['label'].' gagal dijalankan.'])->with('maintenance_output', $output);
            return back()->with('success', $selected['label'].' berhasil dijalankan.')->with('maintenance_output', $output);
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withErrors(['maintenance' => $selected['label'].' gagal dijalankan. Periksa log aplikasi.']);
        }
    }
}
