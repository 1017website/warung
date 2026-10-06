<?php

namespace App\Http\Controllers;

use App\Models\CashierClosing;
use App\Models\Category;
use App\Models\ConnectedDevice;
use App\Models\DailyMenuStock;
use App\Models\Expense;
use App\Models\InventoryAsset;
use App\Models\InventoryAssetLog;
use App\Models\InventoryDailyRecord;
use App\Models\Member;
use App\Models\MemberCard;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductStock;
use App\Models\ProductStorePrice;
use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\StockCount;
use App\Models\StockProduction;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OutletCatalogImporter;
use App\Services\TransactionReportExporter;
use App\Support\EposReceipt;
use App\Support\ImportTemplate;
use App\Support\MenuIcon;
use App\Support\Qty;
use App\Support\SheetValue;
use App\Support\SpreadsheetDownload;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WarungController extends Controller
{
    private ?Store $activeStoreRecord = null;

    /** @var array{0?: string, 1?: int} */
    private array $resolvedStoreId = [];

    private function menuStockForDate(int $productId, Carbon|string|null $date = null, ?int $storeId = null): DailyMenuStock
    {
        $storeId ??= $this->storeId();
        $stockDate = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date ?? today())->toDateString();
        $attributes = [
            'tenant_id' => $this->tenantId(),
            'store_id' => $storeId,
            'product_id' => $productId,
            'stock_date' => $stockDate,
        ];

        if ($stock = DailyMenuStock::where('tenant_id', $this->tenantId())
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->whereDate('stock_date', $stockDate)
            ->lockForUpdate()
            ->first()) {
            return $stock;
        }

        $carryOver = (float) (DailyMenuStock::where('tenant_id', $this->tenantId())
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->whereDate('stock_date', '<', $stockDate)
            ->latest('stock_date')
            ->value('quantity') ?? 0);

        return DailyMenuStock::firstOrCreate($attributes, ['quantity' => $carryOver]);
    }

    /** Every stock screen uses the same carryover, regardless of which menu opens first. */
    private function prepareDailyMenuStocks(?array $storeIds = null): void
    {
        foreach ($storeIds ?? [$this->storeId()] as $storeId) {
            Product::where('tenant_id', $this->tenantId())->where('product_type', 'menu')
                ->whereDoesntHave('dailyStocks', fn ($query) => $query->where('store_id', $storeId)->whereDate('stock_date', today()))
                ->pluck('id')->each(fn ($productId) => $this->menuStockForDate($productId, null, $storeId));
        }
    }

    private function inventoryRecordFor(Product $product, float $opening): InventoryDailyRecord
    {
        return InventoryDailyRecord::firstOrCreate([
            'tenant_id' => $this->tenantId(),
            'store_id' => $this->storeId(),
            'product_id' => $product->id,
            'record_date' => today()->toDateString(),
        ], [
            'opening_quantity' => $opening,
            'used_quantity' => 0,
        ]);
    }

    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    private function storeId(): int
    {
        // Selain superadmin & head of ops, cabang aktif dipaku ke cabang milik akun
        // sehingga session tidak dapat dipakai untuk melihat warung lain.
        $user = auth()->user();
        $requested = (int) session('store_id');
        if (! $user->canAccessAllStores() || ! $requested || $requested === (int) $user->store_id) {
            return (int) $user->store_id;
        }

        // Cabang di session bisa sudah dinonaktifkan. Tanpa pemeriksaan ini, pilihan
        // cabang di header menampilkan cabang lain sementara data yang diubah milik
        // cabang nonaktif tersebut.
        $key = spl_object_id(request()).':'.$user->id.':'.$requested;
        if (($this->resolvedStoreId[0] ?? null) !== $key) {
            $isActive = Store::where('tenant_id', $user->tenant_id)->where('is_active', true)->whereKey($requested)->exists();
            $this->resolvedStoreId = [$key, $isActive ? $requested : (int) $user->store_id];
        }

        return $this->resolvedStoreId[1];
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
            ->first(fn (User $user) => $user->canAccessStore($this->storeId())
                && $user->authorization_pin
                && Hash::check($pin, $user->authorization_pin));
    }

    /** Nilai yang benar-benar masuk per kanal pembayaran (tunai sudah dikurangi kembalian). */
    private function netPaymentRows(Transaction $transaction)
    {
        if ($transaction->payments->isEmpty()) {
            return collect([(object) [
                'method' => $transaction->payment_method,
                'provider' => null,
                'amount' => (float) $transaction->total,
            ]]);
        }

        $changeRemaining = (float) $transaction->change_amount;

        return $transaction->payments->map(function ($payment) use (&$changeRemaining) {
            $amount = (float) $payment->amount;
            if ($payment->method === 'cash' && $changeRemaining > 0) {
                $deduction = min($amount, $changeRemaining);
                $amount -= $deduction;
                $changeRemaining -= $deduction;
            }

            return (object) ['method' => $payment->method, 'provider' => $payment->provider, 'amount' => $amount];
        })->filter(fn ($payment) => $payment->amount > 0)->values();
    }

    private function cashierSummary(): array
    {
        $transactions = Transaction::with('payments')
            ->where('tenant_id', $this->tenantId())
            ->where('store_id', $this->storeId())
            ->where('status', 'completed')
            ->where('transaction_type', 'sale')
            ->whereDate('transacted_at', today())
            ->get();

        $paymentRows = $transactions->flatMap(fn (Transaction $transaction) => $this->netPaymentRows($transaction));
        $paymentSummary = $paymentRows
            ->groupBy(fn ($row) => $row->method.'|'.($row->provider ?: '-'))
            ->map(fn ($rows) => [
                'method' => $rows->first()->method,
                'provider' => $rows->first()->provider,
                'total' => round($rows->sum('amount'), 2),
            ])->values()->all();

        $cashTopups = (float) DB::table('deposit_transactions')
            ->where('tenant_id', $this->tenantId())
            ->where('store_id', $this->storeId())
            ->where('type', 'credit')
            ->whereNull('transaction_id')
            ->where(fn ($query) => $query->where('payment_method', 'cash')->orWhereNull('payment_method'))
            ->whereDate('created_at', today())
            ->sum('amount');
        $cashExpenses = (float) Expense::where('tenant_id', $this->tenantId())
            ->where('store_id', $this->storeId())
            ->where('payment_method', 'cash')
            ->whereDate('expense_date', today())
            ->sum('amount');
        // DP tunai masuk laci pada hari diterima; pembayaran bermetode `dp` di kasir bukan uang baru.
        $cashDp = fn () => Reservation::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->where('dp_method', 'cash');
        $cashReservationDp = (float) $cashDp()->whereDate('dp_paid_at', today())->sum('dp_amount')
            - (float) $cashDp()->where('dp_refunded', true)->whereDate('dp_refunded_at', today())->sum(DB::raw('dp_amount - dp_used'));

        return [
            'transactions' => $transactions->count(),
            'paymentSummary' => $paymentSummary,
            'cashSales' => round($paymentRows->where('method', 'cash')->sum('amount'), 2),
            'cashTopups' => round($cashTopups, 2),
            'cashReservationDp' => round($cashReservationDp, 2),
            'cashExpenses' => round($cashExpenses, 2),
        ];
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
        $store = Store::where('tenant_id', $this->tenantId())->where('is_active', true)->whereKey((int) $value)->firstOrFail();
        $request->session()->put('store_id', $store->id);
        $request->session()->put('view_scope', 'store');

        return back()->with('success', 'Cabang aktif diubah ke '.$store->name.'.');
    }

    public function dashboard()
    {
        $this->prepareDailyMenuStocks($this->isConsolidated() ? $this->stores()->pluck('id')->all() : null);
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
        $this->prepareDailyMenuStocks();
        $storeId = $this->storeId();
        $products = Product::with(['category', 'storePrices' => fn ($q) => $q->where('store_id', $storeId), 'prices', 'dailyStocks' => fn ($q) => $q->where('store_id', $storeId)->whereDate('stock_date', today())])
            ->where('tenant_id', $this->tenantId())->where('product_type', 'menu')->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Product $product) => $product->isAvailableAt($storeId))->values();
        $categories = Category::where('tenant_id', $this->tenantId())->whereIn('id', $products->pluck('category_id')->filter()->unique())->orderBy('name')->get();
        $members = Member::where('tenant_id', $this->tenantId())->where('is_active', true)->orderBy('name')->get();
        $pendingBills = Transaction::with(['items', 'member', 'reservation'])->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->where('status', 'pending')->latest()->get();
        $pendingBillData = $pendingBills->map(fn ($bill) => [
            'id' => $bill->id,
            'invoice' => $bill->invoice_no,
            'member_id' => $bill->member_id,
            'service_type' => $bill->service_type,
            'table_number' => $bill->table_number,
            'online_platform' => $bill->online_platform,
            'discount_type' => $bill->discount_type,
            'discount_value' => (float) $bill->discount_value,
            'reservation' => $bill->reservation?->isOpen() ? $bill->reservation->setRelation('transaction', $bill)->posData() : null,
            'items' => $bill->items->map(fn ($item) => [
                'id' => $item->product_id,
                'price_id' => $item->product_price_id,
                'label' => $item->price_label,
                'name' => $item->product_name,
                'price' => (float) $item->price,
                'qty' => (float) $item->quantity,
                'custom' => (bool) $item->is_custom,
            ])->values(),
        ])->values();
        $posProducts = $products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->priceAt($storeId),
            'online_price' => $product->priceAt($storeId, true),
            'prices' => $product->pricesAt($storeId)->map(fn ($price) => [
                'id' => $price->id,
                'label' => $price->label,
                'price' => $price->priceFor(false),
                'online_price' => $price->priceFor(true),
            ])->values(),
            'category' => $product->category?->name ?? 'Umum',
            'unit' => $product->unit,
            'step' => 1,
            'increment' => strtolower($product->unit) === 'gram' ? 50 : 1,
            'stock' => (float) ($product->dailyStocks->first()?->quantity ?? 0),
        ])->values();
        $chargeConfig = $this->activeStoreRecord()->chargeConfig();
        $eposPrinter = $this->eposPrinter()?->eposConfig();
        $rawbtPrinter = $this->rawbtPrinter();
        // Reservasi hari ini dan tamu yang sudah datang (termasuk yang terlambat dari hari sebelumnya).
        $reservationData = Reservation::with('transaction')->where('tenant_id', $this->tenantId())->where('store_id', $storeId)
            ->whereIn('status', Reservation::OPEN_STATUSES)
            ->where(fn ($query) => $query->whereDate('reserved_at', today())->orWhere('status', 'arrived'))
            ->orderBy('reserved_at')->get()->map(fn (Reservation $reservation) => $reservation->posData())->values();

        return $this->view('pos.index', compact('products', 'categories', 'members', 'pendingBills', 'pendingBillData', 'posProducts', 'chargeConfig', 'eposPrinter', 'rawbtPrinter', 'reservationData'));
    }

    /** Printer Epson ePOS aktif untuk cabang: printer khusus cabang lebih diutamakan. */
    private function rawbtPrinter(?int $storeId = null): ?ConnectedDevice
    {
        $storeId ??= $this->storeId();

        return ConnectedDevice::where('tenant_id', $this->tenantId())
            ->where('type', 'receipt_printer')->where('driver', ConnectedDevice::DRIVER_RAWBT)->where('status', 'active')
            ->where(fn ($query) => $query->where('store_id', $storeId)->orWhereNull('store_id'))
            ->orderByRaw('store_id is null')->orderBy('id')->first();
    }

    private function eposPrinter(?int $storeId = null): ?ConnectedDevice
    {
        $storeId ??= $this->storeId();

        return ConnectedDevice::where('tenant_id', $this->tenantId())
            ->where('type', 'receipt_printer')->where('driver', ConnectedDevice::DRIVER_EPSON_EPOS)->where('status', 'active')
            ->where(fn ($query) => $query->where('store_id', $storeId)->orWhereNull('store_id'))
            ->orderByRaw('store_id is null')->orderBy('id')->get()
            ->first(fn (ConnectedDevice $device) => $device->isEpsonPrinter());
    }

    private function eposJobs(Transaction $transaction, ConnectedDevice $printer): array
    {
        $transaction->loadMissing(['items', 'member', 'user', 'store', 'payments']);
        $store = $transaction->store;

        return [
            'printer' => $printer->eposConfig(),
            'jobs' => EposReceipt::jobs(
                $transaction,
                $store,
                $printer->eposColumns(),
                (bool) ($printer->settings['kitchen_copy'] ?? true),
                (bool) ($printer->settings['open_drawer'] ?? false),
            ),
        ];
    }

    private function validateOrder(Request $request): array
    {
        return $request->validate([
            'items' => ['required', 'array', 'min:1'],
            // Satu produk boleh muncul di beberapa baris bila pilihan harganya berbeda;
            // stoknya diperiksa sebagai jumlah gabungan di orderLines().
            'items.*.id' => ['nullable', 'integer'],
            'items.*.price_id' => ['nullable', 'integer'],
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
            'reservation_id' => ['nullable', 'integer'],
            'transaction_type' => ['nullable', Rule::in(['sale', 'replacement'])],
            'approval_pin' => ['nullable', 'string', 'min:4', 'max:12'],
        ]);
    }

    private function orderLines(array $data, bool $checkStock = true)
    {
        $allowCustom = (bool) $this->activeStoreRecord()->allow_custom_amount;
        $storeId = $this->storeId();
        $online = $data['service_type'] === 'online';

        // Every checkout acquires product locks in the same order, regardless of cart order.
        $lines = collect($data['items'])->sortBy(fn ($line) => [(int) ($line['id'] ?? 0), (int) ($line['price_id'] ?? 0)])->map(function ($line) use ($allowCustom, $storeId, $online) {
            if (empty($line['id'])) {
                abort_unless($allowCustom && ! empty($line['name']) && isset($line['price']), 422, 'Custom amount sedang nonaktif atau datanya belum lengkap.');

                return [
                    'product' => null, 'stock' => null, 'qty' => (float) $line['qty'], 'price_id' => null, 'label' => null,
                    'name' => trim($line['name']), 'category' => 'Custom', 'price' => (float) $line['price'],
                    'cost' => 0, 'subtotal' => (float) $line['price'] * (float) $line['qty'], 'custom' => true,
                ];
            }

            $product = Product::with(['category', 'storePrices' => fn ($q) => $q->where('store_id', $storeId), 'prices'])
                ->where('tenant_id', $this->tenantId())->where('product_type', 'menu')->where('is_active', true)->lockForUpdate()->findOrFail($line['id']);
            abort_unless($product->isAvailableAt($storeId), 422, "Menu {$product->name} tidak dijual di cabang ini.");
            $stock = $this->menuStockForDate($product->id);
            // A normal refresh can read an older MySQL repeatable-read snapshot.
            $stock = DailyMenuStock::whereKey($stock->id)->lockForUpdate()->firstOrFail();

            $priceOption = null;
            if (! empty($line['price_id'])) {
                $priceOption = $product->pricesAt($storeId)->firstWhere('id', (int) $line['price_id']);
                abort_unless($priceOption, 422, "Pilihan harga {$product->name} tidak berlaku di cabang ini.");
            }
            $price = $priceOption ? $priceOption->priceFor($online) : $product->priceAt($storeId, $online);

            return [
                'product' => $product, 'stock' => $stock, 'qty' => (float) $line['qty'],
                'price_id' => $priceOption?->id, 'label' => $priceOption?->label,
                'name' => $product->name.($priceOption ? ' ('.$priceOption->label.')' : ''),
                'category' => $product->category?->name ?? 'Umum', 'price' => $price,
                'cost' => (float) $product->purchase_price, 'subtotal' => $price * (float) $line['qty'], 'custom' => false,
            ];
        })->values();

        if ($checkStock) {
            $lines->filter(fn ($line) => $line['product'])->groupBy(fn ($line) => $line['product']->id)->each(function ($group) {
                $first = $group->first();
                abort_if((float) $first['stock']->quantity < $group->sum('qty'), 422, "Stok {$first['product']->name} tidak mencukupi.");
            });
        }

        return $lines;
    }

    private function itemAttributes(array $line): array
    {
        return [
            'product_id' => $line['product']?->id, 'product_price_id' => $line['price_id'], 'product_name' => $line['name'],
            'price_label' => $line['label'], 'category_name' => $line['category'], 'is_custom' => $line['custom'],
            'quantity' => $line['qty'], 'price' => $line['price'], 'cost' => $line['cost'], 'subtotal' => $line['subtotal'],
        ];
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

    /** Reservasi yang dibuka di Kasir: milik cabang aktif, masih terbuka, dan belum terhubung ke bill lain. */
    private function reservationForOrder(array $data): ?Reservation
    {
        if (empty($data['reservation_id'])) {
            return null;
        }
        $reservation = Reservation::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->lockForUpdate()->findOrFail($data['reservation_id']);
        abort_unless($reservation->isOpen(), 422, "Reservasi {$reservation->code} sudah berstatus {$reservation->statusLabel()}.");
        abort_if($reservation->transaction_id && $reservation->transaction_id !== (int) ($data['pending_transaction_id'] ?? 0), 422, "Reservasi {$reservation->code} sudah terhubung ke open bill lain.");

        return $reservation;
    }

    /** Bill yang sebelumnya membawa reservasi lain dilepas agar DP-nya dapat dipakai kembali. */
    private function releaseOtherReservations(Transaction $transaction, ?Reservation $keep): void
    {
        Reservation::where('transaction_id', $transaction->id)->when($keep, fn ($query) => $query->whereKeyNot($keep->id))->update(['transaction_id' => null]);
    }

    public function checkout(Request $request)
    {
        $data = $this->validateOrder($request);
        $transaction = DB::transaction(function () use ($data) {
            $lines = $this->orderLines($data);
            $member = ! empty($data['member_id']) ? Member::where('tenant_id', $this->tenantId())->where('is_active', true)->lockForUpdate()->findOrFail($data['member_id']) : null;
            $subtotal = (float) $lines->sum('subtotal');
            [$discountType, $discountValue, $discount] = $this->discountFor($data, $subtotal, $member);
            $transactionType = $data['transaction_type'] ?? 'sale';
            $authorizer = null;
            if ($transactionType === 'replacement') {
                $authorizer = $this->supervisorByPin($data['approval_pin'] ?? null);
                abort_unless($authorizer, 422, 'PIN Manager/SPV tidak valid untuk retur pengganti.');
                $discount = $subtotal;
            }
            $charges = $this->activeStoreRecord()->chargesFor($transactionType === 'replacement' ? 0 : $subtotal - $discount, $data['service_type']);
            $total = $transactionType === 'replacement' ? 0 : $subtotal - $discount + $charges['service_charge'] + $charges['tax_amount'];
            $reservation = $this->reservationForOrder($data);
            abort_if($reservation && $transactionType === 'replacement', 422, 'Reservasi tidak dapat dipakai untuk retur pengganti.');
            // DP reservasi sudah diterima sebelumnya: memotong tagihan, sisanya dibayar seperti biasa.
            $dpUsed = $reservation ? min((float) $reservation->dp_amount, $total) : 0;
            [$payments, $paid, $depositUsed] = $total - $dpUsed > 0 ? $this->normalizedPayments($data, $total - $dpUsed, $member) : [collect(), 0, 0];
            if ($dpUsed > 0) {
                $payments->prepend(['method' => 'dp', 'provider' => $reservation->code, 'amount' => $dpUsed]);
                $paid += $dpUsed;
            }
            $primary = $payments->first(fn ($payment) => ! in_array($payment['method'], ['deposit', 'dp'], true))['method'] ?? ($payments->first()['method'] ?? 'cash');
            $primary = $primary === 'dp' ? ($reservation->dp_method ?: 'cash') : $primary;
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
            ] + $charges;
            $trx ? $trx->update($attributes) : $trx = Transaction::create($attributes);

            foreach ($lines as $line) {
                $trx->items()->create($this->itemAttributes($line));
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
            $this->releaseOtherReservations($trx, $reservation);
            $reservation?->update([
                'transaction_id' => $trx->id, 'status' => 'completed', 'dp_used' => $dpUsed,
                'arrived_at' => $reservation->arrived_at ?? now(), 'closed_at' => now(),
            ]);

            return $trx;
        }, 3);

        $response = ['ok' => true, 'invoice' => $transaction->invoice_no, 'print_url' => route('transactions.print', $transaction)];
        $printer = $this->eposPrinter();
        if ($printer && ($printer->settings['auto_print'] ?? true)) {
            $response['epos'] = $this->eposJobs($transaction->fresh(), $printer);
        }

        return response()->json($response);
    }

    /** Perintah cetak ulang struk ke printer Epson ePOS cabang transaksi. */
    public function eposReceipt(Transaction $transaction)
    {
        abort_unless($transaction->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($transaction->store_id);
        abort_unless($transaction->status === 'completed', 422, 'Hanya transaksi selesai yang dapat dicetak.');
        $printer = $this->eposPrinter($transaction->store_id);
        abort_unless($printer, 422, 'Printer Epson ePOS belum diatur untuk cabang ini. Tambahkan di Pengaturan → Perangkat terhubung.');

        return response()->json($this->eposJobs($transaction, $printer));
    }

    public function holdBill(Request $request)
    {
        $data = $this->validateOrder($request);
        $transaction = DB::transaction(function () use ($data) {
            $lines = $this->orderLines($data, false);
            $member = ! empty($data['member_id']) ? Member::where('tenant_id', $this->tenantId())->where('is_active', true)->findOrFail($data['member_id']) : null;
            $subtotal = (float) $lines->sum('subtotal');
            [$discountType, $discountValue, $discount] = $this->discountFor($data, $subtotal, $member);
            $trx = null;
            if (! empty($data['pending_transaction_id'])) {
                $trx = Transaction::where('tenant_id', $this->tenantId())
                    ->where('store_id', $this->storeId())
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->findOrFail($data['pending_transaction_id']);
                $trx->items()->delete();
                $trx->payments()->delete();
            }
            $charges = $this->activeStoreRecord()->chargesFor($subtotal - $discount, $data['service_type']);
            $attributes = [
                'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(), 'member_id' => $member?->id,
                'invoice_no' => $trx?->invoice_no ?? 'BILL-'.now()->format('ymd-His').'-'.strtoupper(Str::random(2)), 'status' => 'pending', 'transaction_type' => 'sale',
                'report_type' => 'real', 'service_type' => $data['service_type'], 'table_number' => $data['table_number'] ?? null,
                'online_platform' => $data['online_platform'] ?? null, 'subtotal' => $subtotal, 'discount_type' => $discountType,
                'discount_value' => $discountValue, 'discount' => $discount, 'total' => $subtotal - $discount + $charges['service_charge'] + $charges['tax_amount'],
                'payment_method' => 'cash', 'paid_amount' => 0, 'change_amount' => 0, 'notes' => $data['notes'] ?? null, 'transacted_at' => now(),
            ] + $charges;
            $reservation = $this->reservationForOrder($data);
            $trx ? $trx->update($attributes) : $trx = Transaction::create($attributes);
            foreach ($lines as $line) {
                $trx->items()->create($this->itemAttributes($line));
            }
            $this->releaseOtherReservations($trx, $reservation);
            $reservation?->update(['transaction_id' => $trx->id, 'status' => 'arrived', 'arrived_at' => $reservation->arrived_at ?? now()]);

            return $trx;
        }, 3);

        return response()->json(['ok' => true, 'invoice' => $transaction->invoice_no]);
    }

    public function cancelPendingBill(Request $request, Transaction $transaction)
    {
        abort_unless($transaction->tenant_id === $this->tenantId() && $transaction->store_id === $this->storeId(), 404);
        abort_unless($transaction->status === 'pending', 422, 'Hanya bill pending yang dapat dibatalkan.');
        $data = $request->validate(['reason' => 'required|string|min:3|max:255']);
        $transaction->update([
            'status' => 'voided',
            'cancel_reason' => $data['reason'],
            'void_authorized_by' => auth()->id(),
            'voided_at' => now(),
        ]);
        $this->releaseOtherReservations($transaction, null);

        return back()->with('success', 'Bill pending dibatalkan tanpa mengubah stok atau saldo member.');
    }

    public function toggleCustomAmount(Request $request)
    {
        abort_unless(auth()->user()->isSupervisor(), 403);
        $this->activeStoreRecord()->update(['allow_custom_amount' => $request->boolean('enabled')]);

        return back()->with('success', 'Custom amount '.($request->boolean('enabled') ? 'diaktifkan.' : 'dinonaktifkan.'));
    }

    public function reservations(Request $request)
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(array_merge(['open', 'all'], array_keys(Reservation::STATUSES)))],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $date = Carbon::parse($filters['date'] ?? today());
        $status = $filters['status'] ?? 'all';
        $scope = fn () => Reservation::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId());
        $reservations = $scope()->with(['user', 'transaction'])
            ->whereDate('reserved_at', $date)
            ->when($status === 'open', fn ($query) => $query->whereIn('status', Reservation::OPEN_STATUSES))
            ->when(! in_array($status, ['open', 'all'], true), fn ($query) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($inner) => $inner->where('customer_name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
            ->orderBy('reserved_at')->get();
        $summary = [
            'count' => $reservations->whereNotIn('status', ['cancelled'])->count(),
            'guests' => $reservations->whereIn('status', ['booked', 'arrived', 'completed'])->sum('guests'),
            'open' => $reservations->whereIn('status', Reservation::OPEN_STATUSES)->count(),
            'dp' => $reservations->where('dp_refunded', false)->sum(fn ($reservation) => (float) $reservation->dp_amount),
        ];
        $upcoming = $scope()->whereIn('status', Reservation::OPEN_STATUSES)->where('reserved_at', '>=', today()->addDay())
            ->selectRaw('DATE(reserved_at) as day, COUNT(*) as total')->groupBy('day')->orderBy('day')->take(7)->get();

        return $this->view('reservations.index', compact('reservations', 'date', 'status', 'summary', 'upcoming', 'filters'));
    }

    private function reservationData(Request $request): array
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'reserved_date' => 'required|date',
            'reserved_time' => 'required|date_format:H:i',
            'guests' => 'required|integer|min:1|max:1000',
            'table_number' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
            'dp_amount' => 'nullable|numeric|min:0',
            'dp_method' => ['nullable', Rule::in(array_keys(Reservation::DP_METHODS)), Rule::requiredIf(fn () => (float) $request->input('dp_amount') > 0)],
            'dp_provider' => ['nullable', 'string', 'max:80', Rule::requiredIf(fn () => (float) $request->input('dp_amount') > 0 && in_array($request->input('dp_method'), ['qris', 'transfer', 'debit'], true))],
        ], [], ['dp_method' => 'cara bayar DP', 'dp_provider' => 'bank/provider DP', 'reserved_date' => 'tanggal', 'reserved_time' => 'jam']);
        $data['reserved_at'] = Carbon::parse($data['reserved_date'].' '.$data['reserved_time']);
        $data['dp_amount'] = (float) ($data['dp_amount'] ?? 0);
        if ($data['dp_amount'] <= 0) {
            $data['dp_method'] = $data['dp_provider'] = null;
        } elseif (($data['dp_method'] ?? null) === 'cash') {
            $data['dp_provider'] = null;
        }
        unset($data['reserved_date'], $data['reserved_time']);

        return $data;
    }

    public function storeReservation(Request $request)
    {
        $data = $this->reservationData($request);
        $reservation = Reservation::create($data + [
            'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(), 'status' => 'booked',
            'code' => 'RSV-'.now()->format('ymd').'-'.strtoupper(Str::random(4)),
            'dp_paid_at' => $data['dp_amount'] > 0 ? now() : null,
        ]);

        return redirect()->route('reservations', ['date' => $reservation->reserved_at->toDateString()])
            ->with('success', "Reservasi {$reservation->code} a.n. {$reservation->customer_name} tersimpan.");
    }

    public function updateReservation(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->tenant_id === $this->tenantId() && $reservation->store_id === $this->storeId(), 404);
        abort_unless($reservation->isOpen(), 422, 'Reservasi yang sudah selesai atau batal tidak dapat diubah.');
        $data = $this->reservationData($request);
        $dpChanged = abs($data['dp_amount'] - (float) $reservation->dp_amount) > 0.004 || $data['dp_method'] !== $reservation->dp_method;
        if ($dpChanged) {
            // DP yang sudah diterima tidak diganti diam-diam setelah hari penerimaannya (kas harian sudah ditutup).
            abort_if($reservation->dp_paid_at && ! $reservation->dp_paid_at->isToday() && (float) $reservation->dp_amount > 0, 422, 'DP diterima pada hari lain. Batalkan reservasi (DP dikembalikan) lalu buat reservasi baru bila nominal DP berubah.');
            $data['dp_paid_at'] = $data['dp_amount'] > 0 ? ($reservation->dp_paid_at ?? now()) : null;
        }
        $reservation->update($data);

        return back()->with('success', "Reservasi {$reservation->code} diperbarui.");
    }

    public function updateReservationStatus(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->tenant_id === $this->tenantId() && $reservation->store_id === $this->storeId(), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['arrived', 'cancelled', 'no_show', 'booked'])],
            'reason' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $request->input('status') === 'cancelled')],
            'refund_dp' => ['nullable', 'boolean'],
        ], [], ['reason' => 'alasan pembatalan']);

        DB::transaction(function () use ($reservation, $data) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($reservation->isOpen(), 422, "Reservasi {$reservation->code} sudah berstatus {$reservation->statusLabel()}.");
            if ($data['status'] === 'arrived') {
                $reservation->update(['status' => 'arrived', 'arrived_at' => now()]);

                return;
            }
            if ($data['status'] === 'booked') {
                abort_if($reservation->transaction_id, 422, 'Reservasi sudah memiliki open bill di Kasir.');
                $reservation->update(['status' => 'booked', 'arrived_at' => null]);

                return;
            }
            abort_if($reservation->transaction_id, 422, 'Reservasi masih terhubung ke open bill. Batalkan open bill di Kasir terlebih dahulu.');
            $refund = (bool) ($data['refund_dp'] ?? false) && (float) $reservation->dp_amount > 0;
            $reservation->update([
                'status' => $data['status'], 'cancel_reason' => $data['reason'] ?? null, 'closed_at' => now(),
                'dp_refunded' => $refund, 'dp_refunded_at' => $refund ? now() : null,
            ]);
        });
        $messages = ['arrived' => 'Tamu ditandai datang. Buka reservasi di Kasir untuk memotong DP.', 'booked' => 'Status kembali menjadi dipesan.', 'cancelled' => 'Reservasi dibatalkan.', 'no_show' => 'Reservasi ditandai tidak datang.'];

        return back()->with('success', $messages[$data['status']]);
    }

    public function closeCashier()
    {
        $sales = DB::table('transaction_items')->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->leftJoin('products', 'products.id', '=', 'transaction_items.product_id')
            ->where('transactions.tenant_id', $this->tenantId())->where('transactions.store_id', $this->storeId())
            ->where('transactions.status', 'completed')->where('transactions.transaction_type', 'sale')
            ->whereDate('transactions.transacted_at', today())->select('transaction_items.product_name', DB::raw("COALESCE(products.unit, 'item') as unit"), DB::raw('SUM(transaction_items.quantity) as quantity'))
            ->groupBy('transaction_items.product_name', 'products.unit')->orderBy('transaction_items.product_name')->get();

        $summary = $this->cashierSummary();
        $closing = CashierClosing::with(['user', 'authorizer'])
            ->where('tenant_id', $this->tenantId())
            ->where('store_id', $this->storeId())
            ->whereDate('closing_date', today())
            ->first();

        return $this->view('pos.close', compact('sales', 'summary', 'closing'));
    }

    public function storeCashierClosing(Request $request)
    {
        $data = $request->validate([
            'opening_cash' => 'required|numeric|min:0',
            'actual_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'approval_pin' => 'nullable|string|min:4|max:12',
        ]);
        $authorizer = auth()->user()->isSupervisor()
            ? auth()->user()
            : $this->supervisorByPin($data['approval_pin'] ?? null);
        abort_unless($authorizer, 422, 'PIN Manager/SPV cabang aktif diperlukan untuk tutup kasir.');

        $summary = $this->cashierSummary();
        $expected = (float) $data['opening_cash'] + $summary['cashSales'] + $summary['cashTopups'] + $summary['cashReservationDp'] - $summary['cashExpenses'];
        $existing = CashierClosing::where('tenant_id', $this->tenantId())
            ->where('store_id', $this->storeId())
            ->whereDate('closing_date', today())
            ->first();
        abort_if($existing && ! auth()->user()->isSupervisor(), 422, 'Tutup kasir hari ini sudah tersimpan. Perubahan ulang harus dilakukan Manager/SPV.');

        CashierClosing::updateOrCreate(
            ['store_id' => $this->storeId(), 'closing_date' => today()->toDateString()],
            [
                'tenant_id' => $this->tenantId(),
                'user_id' => auth()->id(),
                'authorized_by' => $authorizer->id,
                'opening_cash' => $data['opening_cash'],
                'cash_sales' => $summary['cashSales'],
                'cash_topups' => $summary['cashTopups'],
                'cash_reservation_dp' => $summary['cashReservationDp'],
                'cash_expenses' => $summary['cashExpenses'],
                'expected_cash' => $expected,
                'actual_cash' => $data['actual_cash'],
                'difference' => (float) $data['actual_cash'] - $expected,
                'payment_summary' => $summary['paymentSummary'],
                'notes' => $data['notes'] ?? null,
                'closed_at' => now(),
            ]
        );

        return back()->with('success', 'Rekonsiliasi dan tutup kasir hari ini berhasil disimpan.');
    }

    public function products(Request $request)
    {
        $this->prepareDailyMenuStocks();
        $filters = $request->validate(['q' => 'nullable|string|max:80', 'type' => ['nullable', Rule::in(['menu', 'ingredient'])], 'category' => 'nullable|integer']);
        // Pencarian di server: katalog outlet berisi ratusan produk sehingga tidak cukup mencari di halaman aktif.
        $products = Product::with(['category', 'storePrices', 'prices'])
            ->withSum(['stocks as warehouse_stock' => fn ($q) => $q->where('store_id', $this->storeId())], 'quantity')
            ->withSum(['dailyStocks as daily_stock' => fn ($q) => $q->where('store_id', $this->storeId())->whereDate('stock_date', today())], 'quantity')
            ->where('tenant_id', $this->tenantId())
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")->orWhere('ingredient_sku', 'like', "%{$q}%")->orWhere('barcode', 'like', "%{$q}%")))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('product_type', $type))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category_id', $category))
            ->latest()->orderBy('name')->paginate(20)->withQueryString();
        $categories = Category::where('tenant_id', $this->tenantId())->orderBy('name')->get();
        $archivedProducts = Product::onlyTrashed()->with('category')
            ->where('tenant_id', $this->tenantId())->latest('deleted_at')->take(20)->get();
        $archivedCategories = Category::onlyTrashed()->where('tenant_id', $this->tenantId())
            ->latest('deleted_at')->take(20)->get();
        $priceStores = $this->stores();
        $priceStoreId = $this->isConsolidated() ? null : $this->storeId();

        return $this->view('products.index', compact('products', 'categories', 'archivedProducts', 'archivedCategories', 'priceStores', 'priceStoreId', 'filters'));
    }

    /**
     * Harga khusus per warung dan pilihan harga tambahan satu SKU disimpan sekaligus.
     * Harga warung yang dikosongkan kembali mengikuti harga default produk.
     */
    public function updateProductPrices(Request $request, Product $product)
    {
        abort_unless($product->tenant_id === $this->tenantId(), 404);
        abort_unless($product->product_type === 'menu', 422, 'Harga jual hanya berlaku untuk menu siap jual.');
        $storeIds = $this->stores()->pluck('id')->all();
        $data = $request->validate([
            'stores' => ['nullable', 'array'],
            'stores.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'stores.*.online_selling_price' => ['nullable', 'numeric', 'min:0'],
            'stores.*.is_available' => ['nullable', 'boolean'],
            'prices' => ['nullable', 'array', 'max:20'],
            'prices.*.id' => ['nullable', 'integer'],
            'prices.*.label' => ['required', 'string', 'max:60'],
            'prices.*.price' => ['required', 'numeric', 'min:0'],
            'prices.*.online_price' => ['nullable', 'numeric', 'min:0'],
            'prices.*.store_id' => ['nullable', 'integer', Rule::in($storeIds)],
        ], [], [
            'prices.*.label' => 'nama pilihan harga',
            'prices.*.price' => 'nominal pilihan harga',
        ]);
        foreach (array_keys($data['stores'] ?? []) as $storeId) {
            abort_unless(in_array((int) $storeId, $storeIds, true), 403, 'Cabang di luar akses akun.');
        }
        $labels = collect($data['prices'] ?? [])->map(fn ($row) => Str::lower(trim($row['label'])).'|'.($row['store_id'] ?? ''));
        abort_if($labels->duplicates()->isNotEmpty(), 422, 'Nama pilihan harga tidak boleh sama untuk warung yang sama.');

        DB::transaction(function () use ($product, $data, $storeIds) {
            // Akun satu cabang hanya melihat dan mengelola harga cabangnya serta harga semua warung.
            $visiblePrices = fn () => $product->prices()->where(fn ($query) => $query->whereNull('store_id')->orWhereIn('store_id', $storeIds));
            foreach ($data['stores'] ?? [] as $storeId => $row) {
                $values = [
                    'selling_price' => isset($row['selling_price']) && $row['selling_price'] !== '' ? $row['selling_price'] : null,
                    'online_selling_price' => isset($row['online_selling_price']) && $row['online_selling_price'] !== '' ? $row['online_selling_price'] : null,
                    'is_available' => (bool) ($row['is_available'] ?? false),
                ];
                if ($values['selling_price'] === null && $values['online_selling_price'] === null && $values['is_available']) {
                    ProductStorePrice::where('product_id', $product->id)->where('store_id', $storeId)->delete();

                    continue;
                }
                ProductStorePrice::updateOrCreate(['product_id' => $product->id, 'store_id' => (int) $storeId], $values + ['tenant_id' => $this->tenantId()]);
            }

            $keep = [];
            foreach (array_values($data['prices'] ?? []) as $position => $row) {
                $values = [
                    'tenant_id' => $this->tenantId(), 'label' => trim($row['label']), 'price' => $row['price'],
                    'online_price' => isset($row['online_price']) && $row['online_price'] !== '' ? $row['online_price'] : null,
                    'store_id' => $row['store_id'] ?? null, 'position' => $position,
                ];
                $price = ! empty($row['id']) ? $visiblePrices()->whereKey($row['id'])->first() : null;
                $price ? $price->update($values) : $price = $product->prices()->create($values);
                $keep[] = $price->id;
            }
            $visiblePrices()->whereNotIn('id', $keep)->delete();
        });

        return back()->with('success', 'Harga '.$product->name.' berhasil diperbarui.');
    }

    private function productData(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'icon' => MenuIcon::rules(),
            'sku' => ['required', 'string', 'max:50', Rule::unique('products')->where('tenant_id', $this->tenantId())
                ->where('product_type', $product?->product_type ?? ($request->input('product_type') === 'ingredient' ? 'ingredient' : 'menu'))->ignore($product?->id)],
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

    public function destroyProduct(int $product)
    {
        // A repeated/double submission remains harmless instead of turning a
        // successful archive into a 404 page.
        $product = Product::withTrashed()->where('tenant_id', $this->tenantId())->findOrFail($product);
        if ($product->trashed()) {
            return back()->with('success', 'Produk sudah berada di arsip.');
        }

        $product->update(['is_active' => false]);
        $product->delete();

        return back()->with('success', 'Produk dipindahkan ke arsip.');
    }

    public function restoreProduct(int $product)
    {
        $product = Product::withTrashed()->where('tenant_id', $this->tenantId())->findOrFail($product);
        $product->restore();
        $product->update(['is_active' => true]);

        return back()->with('success', 'Produk '.$product->name.' berhasil dipulihkan.');
    }

    private function categoryData(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('categories')->where('tenant_id', $this->tenantId())->ignore($category?->id)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => MenuIcon::rules(),
        ]);
    }

    public function storeCategory(Request $request)
    {
        Category::create($this->categoryData($request) + ['tenant_id' => $this->tenantId()]);

        return back()->with('success', 'Kategori produk berhasil ditambahkan.');
    }

    public function updateCategory(Request $request, Category $category)
    {
        abort_unless($category->tenant_id === $this->tenantId(), 404);
        $category->update($this->categoryData($request, $category));

        return back()->with('success', 'Kategori produk berhasil diperbarui.');
    }

    public function destroyCategory(Category $category)
    {
        abort_unless($category->tenant_id === $this->tenantId(), 404);
        abort_if($category->products()->where('is_active', true)->exists(), 422, 'Kategori masih digunakan produk aktif. Pindahkan produknya terlebih dahulu.');
        $category->delete();

        return back()->with('success', 'Kategori dipindahkan ke arsip.');
    }

    public function restoreCategory(int $category)
    {
        $category = Category::withTrashed()->where('tenant_id', $this->tenantId())->findOrFail($category);
        $category->restore();

        return back()->with('success', 'Kategori '.$category->name.' berhasil dipulihkan.');
    }

    public function exportProducts()
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Produk');
        $headers = ['SKU', 'Barcode', 'Nama', 'Jenis', 'Kategori', 'Satuan', 'Harga Beli', 'Harga Normal', 'Harga Online', 'Stok Minimum', 'Ikon'];
        $sheet->fromArray($headers, null, 'A1');
        $storeSheet = $spreadsheet->createSheet()->setTitle('Harga Warung');
        $storeSheet->fromArray(['SKU', 'Nama Produk', 'Kode Warung', 'Nama Warung', 'Dijual', 'Harga Normal', 'Harga Online'], null, 'A1');
        $priceSheet = $spreadsheet->createSheet()->setTitle('Pilihan Harga');
        $priceSheet->fromArray(['SKU', 'Nama Produk', 'Nama Harga', 'Harga', 'Harga Online', 'Kode Warung'], null, 'A1');
        $stores = Store::where('tenant_id', $this->tenantId())->pluck('code', 'id');
        $row = $storeRow = $priceRow = 2;
        // SKU dan barcode ditulis sebagai teks: angka panjang tidak menjadi 8,99E+12
        // di Excel dan nol di depan SKU tidak hilang saat file diimpor kembali.
        Product::with(['category', 'storePrices.store', 'prices'])->where('tenant_id', $this->tenantId())->orderBy('name')->orderBy('id')
            ->each(function (Product $product) use ($sheet, $storeSheet, $priceSheet, $stores, &$row, &$storeRow, &$priceRow) {
                $sheet->setCellValueExplicit('A'.$row, $product->sku, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B'.$row, (string) $product->barcode, DataType::TYPE_STRING);
                $sheet->fromArray([[$product->name, $product->product_type, $product->category?->name, $product->unit, (float) $product->purchase_price, (float) $product->selling_price, (float) $product->online_selling_price, $product->minimum_stock, $product->icon]], null, 'C'.$row++);
                foreach ($product->storePrices as $price) {
                    $storeSheet->setCellValueExplicit('A'.$storeRow, $product->sku, DataType::TYPE_STRING);
                    $storeSheet->setCellValueExplicit('C'.$storeRow, (string) $price->store?->code, DataType::TYPE_STRING);
                    $storeSheet->fromArray([[$product->name]], null, 'B'.$storeRow);
                    $storeSheet->fromArray([[$price->store?->name, $price->is_available ? 'Ya' : 'Tidak', $price->selling_price === null ? null : (float) $price->selling_price, $price->online_selling_price === null ? null : (float) $price->online_selling_price]], null, 'D'.$storeRow++);
                }
                foreach ($product->prices as $price) {
                    $priceSheet->setCellValueExplicit('A'.$priceRow, $product->sku, DataType::TYPE_STRING);
                    $priceSheet->fromArray([[$product->name, $price->label, (float) $price->price, $price->online_price === null ? null : (float) $price->online_price]], null, 'B'.$priceRow);
                    $priceSheet->setCellValueExplicit('F'.$priceRow++, (string) ($stores[$price->store_id] ?? ''), DataType::TYPE_STRING);
                }
            });
        foreach ([[$sheet, 'K', 'G'], [$storeSheet, 'G', 'F'], [$priceSheet, 'F', 'D']] as [$target, $lastColumn, $firstMoney]) {
            $target->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
            $target->freezePane('A2');
            foreach (range('A', $lastColumn) as $column) {
                $target->getColumnDimension($column)->setAutoSize(true);
            }
            $target->getStyle($firstMoney.'2:'.$lastColumn.max(2, $target->getHighestRow()))->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet->getStyle('J2:K'.max(2, $sheet->getHighestRow()))->getNumberFormat()->setFormatCode('General');
        $priceSheet->getStyle('F2:F'.max(2, $priceSheet->getHighestRow()))->getNumberFormat()->setFormatCode('@');
        $spreadsheet->setActiveSheetIndex(0);

        return SpreadsheetDownload::response($spreadsheet, 'produk-'.now()->format('Ymd').'.xlsx');
    }

    /** @return list<Collection> Baris sheet sebagai koleksi berkunci nama kolom. */
    private function sheetRecords(?Worksheet $sheet, array $aliases = []): array
    {
        if (! $sheet) {
            return [];
        }
        // Nilai mentah, bukan tampilan: harga berformat "15,000" tidak terbaca 15.
        $rows = $sheet->toArray(null, true, false, false);
        $headers = collect(array_shift($rows))->map(fn ($value) => Str::slug((string) $value, '_'));

        return collect($rows)
            ->map(fn ($row) => $headers->combine(array_pad(array_slice($row, 0, $headers->count()), $headers->count(), null))
                ->mapWithKeys(fn ($value, $key) => [$aliases[$key] ?? $key => is_string($value) ? trim($value) : $value]))
            ->all();
    }

    private function sheetText(mixed $value): string
    {
        return SheetValue::text($value);
    }

    private function sheetNumber(mixed $value): ?float
    {
        return SheetValue::number($value);
    }

    /** Template kosong + sheet Petunjuk untuk format impor workbook outlet atau standar. */
    public function productImportTemplate(string $format)
    {
        abort_unless(array_key_exists($format, ImportTemplate::FORMATS), 404);

        return SpreadsheetDownload::response(ImportTemplate::workbook($format, $this->stores()), ImportTemplate::FORMATS[$format]['filename']);
    }

    public function importProducts(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120']);
        $file = $request->file('file');
        if (in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            // CSV dibaca sebagai teks: "12.500" tidak berubah menjadi 12,5 dan SKU 000777 tetap utuh.
            $reader = IOFactory::createReader('Csv');
            $reader->setValueBinder(new StringValueBinder);
            $book = $reader->load($file->getRealPath());
        } else {
            $book = IOFactory::load($file->getRealPath());
        }
        // Workbook outlet (sheet MATANG/MENTAH/SUPPORT/CV atau file "Stok ...") memakai format sendiri.
        if (OutletCatalogImporter::detects($book)) {
            $result = (new OutletCatalogImporter($this->tenantId(), $this->storeId(), (int) auth()->id()))->import($book);
            $book->disconnectWorksheets();

            return back()->with('success', $result['message'])->with('import_notes', $result['notes']);
        }
        $aliases = [
            'jenis' => 'product_type', 'nama' => 'name', 'kategori' => 'category', 'satuan' => 'unit',
            'harga_beli' => 'purchase_price', 'harga_normal' => 'selling_price', 'harga_jual' => 'selling_price',
            'harga_online' => 'online_selling_price', 'stok_minimum' => 'minimum_stock', 'stok_awal' => 'initial_stock', 'ikon' => 'icon',
        ];
        $records = $this->sheetRecords($book->getSheetByName('Produk') ?? $book->getSheet(0), $aliases);
        $storeRecords = $this->sheetRecords($book->getSheetByName('Harga Warung'));
        $priceRecords = $this->sheetRecords($book->getSheetByName('Pilihan Harga'));
        $book->disconnectWorksheets();
        $count = 0;
        DB::transaction(function () use ($records, $storeRecords, $priceRecords, &$count) {
            foreach ($records as $record) {
                $sku = $this->sheetText($record->get('sku'));
                $name = $this->sheetText($record->get('name'));
                if ($sku === '' || $name === '') {
                    continue;
                }
                $categoryId = null;
                if ($category = $this->sheetText($record->get('category'))) {
                    $categoryId = Category::firstOrCreate(['tenant_id' => $this->tenantId(), 'name' => $category], ['color' => '#78978a'])->id;
                }
                $icon = $this->sheetText($record->get('icon'));
                $icon = $icon !== '' && Validator::make(['icon' => $icon], ['icon' => MenuIcon::rules()])->passes() ? $icon : null;
                $sellingPrice = $this->sheetNumber($record->get('selling_price')) ?? 0;
                $productType = $this->sheetText($record->get('product_type')) === 'ingredient' ? 'ingredient' : 'menu';
                $product = Product::withTrashed()->updateOrCreate(
                    ['tenant_id' => $this->tenantId(), 'product_type' => $productType, 'sku' => $sku],
                    ['name' => $name, 'barcode' => $this->sheetText($record->get('barcode')) ?: null, 'category_id' => $categoryId,
                        'unit' => $this->sheetText($record->get('unit')) ?: 'pcs', 'purchase_price' => $this->sheetNumber($record->get('purchase_price')) ?? 0,
                        'selling_price' => $sellingPrice, 'online_selling_price' => $this->sheetNumber($record->get('online_selling_price')) ?: $sellingPrice,
                        'minimum_stock' => (int) ($this->sheetNumber($record->get('minimum_stock')) ?? 0), 'is_active' => true, 'deleted_at' => null]
                    // Template lama tanpa kolom Ikon tidak menghapus ikon yang sudah dipilih.
                    + ($record->has('icon') ? ['icon' => $icon] : [])
                );
                $initial = (float) ($this->sheetNumber($record->get('initial_stock')) ?? 0);
                if ($product->product_type === 'menu') {
                    DailyMenuStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'stock_date' => today()], ['quantity' => $initial]);
                } else {
                    ProductStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id], ['quantity' => $initial]);
                }
                $count++;
            }

            $this->importProductPrices($storeRecords, $priceRecords);
        });

        return back()->with('success', $count.' produk berhasil diimpor/diperbarui.');
    }

    /** Sheet Harga Warung dan Pilihan Harga bersifat opsional; baris yang tidak dikenali dilewati. */
    private function importProductPrices(array $storeRecords, array $priceRecords): void
    {
        $stores = $this->stores()->keyBy(fn (Store $store) => Str::lower($store->code));
        $products = Product::where('tenant_id', $this->tenantId())->where('product_type', 'menu')->get()->keyBy('sku');
        foreach ($storeRecords as $record) {
            $product = $products->get($this->sheetText($record->get('sku')));
            $store = $stores->get(Str::lower($this->sheetText($record->get('kode_warung'))));
            if (! $product || ! $store) {
                continue;
            }
            $available = ! in_array(Str::lower($this->sheetText($record->get('dijual'))), ['tidak', 'no', '0', 'false'], true);
            ProductStorePrice::updateOrCreate(['product_id' => $product->id, 'store_id' => $store->id], [
                'tenant_id' => $this->tenantId(), 'is_available' => $available,
                'selling_price' => $this->sheetNumber($record->get('harga_normal')),
                'online_selling_price' => $this->sheetNumber($record->get('harga_online')),
            ]);
        }
        foreach ($priceRecords as $position => $record) {
            $product = $products->get($this->sheetText($record->get('sku')));
            $label = $this->sheetText($record->get('nama_harga'));
            $price = $this->sheetNumber($record->get('harga'));
            $storeCode = Str::lower($this->sheetText($record->get('kode_warung')));
            $store = $storeCode === '' ? null : $stores->get($storeCode);
            if (! $product || $label === '' || $price === null || ($storeCode !== '' && ! $store)) {
                continue;
            }
            ProductPrice::updateOrCreate(
                ['product_id' => $product->id, 'label' => mb_substr($label, 0, 60), 'store_id' => $store?->id],
                ['tenant_id' => $this->tenantId(), 'price' => $price, 'online_price' => $this->sheetNumber($record->get('harga_online')), 'position' => $position]
            );
        }
    }

    public function inventory()
    {
        Product::where('tenant_id', $this->tenantId())->where('is_active', true)->each(function ($product) {
            $product->product_type === 'menu'
                ? $this->menuStockForDate($product->id)
                : ProductStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id], ['quantity' => 0]);
        });
        $movementsToday = DB::table('stock_movements')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->whereDate('created_at', today())->get()->groupBy('product_id');
        $counts = StockCount::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->whereDate('count_date', today())->get()->keyBy('product_id');
        $ingredientStocks = ProductStock::with('product.category')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())
            ->whereHas('product', fn ($q) => $q->where('product_type', 'ingredient'))->orderBy('quantity')->get()->map(function ($stock) use ($movementsToday) {
                $moves = $movementsToday->get($stock->product_id, collect());
                $record = $this->inventoryRecordFor($stock->product, (float) $stock->quantity - (float) $moves->sum('quantity'));
                $stock->opening = $record->opening_quantity;
                $stock->incoming = $moves->filter(fn ($move) => $move->quantity > 0 && in_array($move->activity, ['purchase', 'adjustment'], true))->sum('quantity');
                $stock->used = $record->used_quantity;
                $stock->processed = abs($moves->where('activity', 'production')->where('quantity', '<', 0)->sum('quantity'));
                $stock->waste = (float) $record->waste_quantity;
                $stock->inventory_notes = $record->notes;

                return $stock;
            });
        $menuStocks = DailyMenuStock::with('product.category')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->whereDate('stock_date', today())
            ->whereHas('product', fn ($q) => $q->where('product_type', 'menu'))->orderBy('quantity')->get()->map(function ($stock) use ($movementsToday, $counts) {
                $moves = $movementsToday->get($stock->product_id, collect());
                $record = $this->inventoryRecordFor($stock->product, (float) $stock->quantity - (float) $moves->sum('quantity'));
                $stock->opening = $record->opening_quantity;
                $stock->produced = $moves->where('activity', 'production')->where('quantity', '>', 0)->sum('quantity');
                $stock->sold = abs($moves->where('activity', 'sale')->sum('quantity'));
                $stock->consumption = abs($moves->where('activity', 'consumption')->sum('quantity'));
                $stock->reprocessed = abs($moves->where('activity', 'reprocess_out')->sum('quantity'));
                $stock->waste = (float) $record->waste_quantity;
                $stock->inventory_notes = $record->notes;
                $stock->count = $counts->get($stock->product_id);

                return $stock;
            });
        $inventoryProducts = Product::where('tenant_id', $this->tenantId())->where('is_active', true)->orderBy('product_type')->orderBy('name')->get();
        $movements = DB::table('stock_movements')->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.tenant_id', $this->tenantId())->where('stock_movements.store_id', $this->storeId())
            ->select('stock_movements.*', 'products.name as product_name')->latest('stock_movements.created_at')->take(12)->get();
        $productions = StockProduction::with(['ingredient', 'menu'])->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->latest()->take(10)->get();
        $canEditOpening = auth()->user()->canManageOpeningStock();

        return $this->view('inventory.index', compact('ingredientStocks', 'menuStocks', 'inventoryProducts', 'movements', 'productions', 'canEditOpening'));
    }

    public function adjustStock(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer', 'type' => ['required', Rule::in(['adjustment_in', 'adjustment_out', 'consumption'])], 'quantity' => 'required|numeric|min:0.001', 'notes' => 'required|string|max:255']);
        DB::transaction(function () use ($data) {
            $product = Product::where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($data['product_id']);
            $stock = $product->product_type === 'menu'
                ? $this->menuStockForDate($product->id)
                : ProductStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id], ['quantity' => 0]);
            $stock = $stock->newQuery()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
            $positive = $data['type'] === 'adjustment_in';
            $delta = $positive ? $data['quantity'] : -$data['quantity'];
            abort_if($stock->quantity + $delta < 0, 422, 'Stok tidak boleh menjadi negatif.');
            $stock->increment('quantity', $delta);
            DB::table('stock_movements')->insert([
                'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id, 'user_id' => auth()->id(),
                'type' => $positive ? 'adjustment_in' : 'adjustment_out', 'activity' => $data['type'] === 'consumption' ? 'consumption' : 'adjustment',
                'quantity' => $delta, 'notes' => $data['notes'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);

        return back()->with('success', 'Stok berhasil disesuaikan.');
    }

    public function updateInventoryRow(Request $request, Product $product)
    {
        abort_unless($product->tenant_id === $this->tenantId(), 404);
        $data = $request->validate([
            'opening_quantity' => 'sometimes|numeric|min:0',
            'used_quantity' => 'sometimes|numeric|min:0',
            'waste_quantity' => 'sometimes|numeric|min:0',
            'notes' => 'sometimes|nullable|string|max:500',
        ]);
        abort_if($data === [], 422, 'Tidak ada perubahan stok yang dikirim.');
        if (array_key_exists('opening_quantity', $data)) {
            abort_unless(auth()->user()->canManageOpeningStock(), 403, 'Stok awal hanya dapat diubah Admin Operasional atau jenjang di atasnya.');
        }
        if (array_key_exists('used_quantity', $data)) {
            abort_unless($product->product_type === 'ingredient', 422, 'Stok terpakai manual hanya berlaku untuk bahan baku.');
        }

        $result = $this->applyInventoryRowChanges($product, $data);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => 'Perubahan stok tersimpan.', 'data' => $result])
            : back()->with('success', 'Perubahan stok tersimpan.');
    }

    /**
     * Catat bahan rusak / tidak layak / tidak bisa diproses ulang. Jenis stok dipilih
     * lebih dulu (mentah = bahan baku, matang = olahan), lalu jumlahnya menambah
     * kolom Rusak hari ini dan mengurangi saldo stok.
     */
    public function storeWaste(Request $request)
    {
        $data = $request->validate([
            'stock_type' => ['required', Rule::in(['raw', 'cooked'])],
            'product_id' => 'required|integer',
            'quantity' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:255',
        ]);
        $product = Product::where('tenant_id', $this->tenantId())->where('is_active', true)
            ->where('product_type', $data['stock_type'] === 'raw' ? 'ingredient' : 'menu')->findOrFail($data['product_id']);
        $this->applyInventoryRowChanges($product, ['waste_add' => (float) $data['quantity']], $data['reason']);

        return back()->with('success', 'Stok rusak '.$product->name.' tercatat dan saldo stok berkurang.');
    }

    private function applyInventoryRowChanges(Product $product, array $data, ?string $wasteReason = null): array
    {
        return DB::transaction(function () use ($product, $data, $wasteReason) {
            if ($product->product_type === 'menu') {
                $this->menuStockForDate($product->id);
                $stock = DailyMenuStock::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())
                    ->where('product_id', $product->id)->whereDate('stock_date', today())->lockForUpdate()->firstOrFail();
            } else {
                ProductStock::firstOrCreate(['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id], ['quantity' => 0]);
                $stock = ProductStock::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())
                    ->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            }

            $movementTotal = (float) DB::table('stock_movements')->where('tenant_id', $this->tenantId())
                ->where('store_id', $this->storeId())->where('product_id', $product->id)->whereDate('created_at', today())->sum('quantity');
            $record = $this->inventoryRecordFor($product, (float) $stock->quantity - $movementTotal);
            $record = InventoryDailyRecord::lockForUpdate()->findOrFail($record->id);
            $balance = (float) $stock->quantity;

            if (array_key_exists('opening_quantity', $data)) {
                $openingDelta = (float) $data['opening_quantity'] - (float) $record->opening_quantity;
                abort_if($balance + $openingDelta < 0, 422, 'Koreksi stok awal membuat sisa stok negatif.');
                $balance += $openingDelta;
                $record->opening_quantity = $data['opening_quantity'];
                $record->opening_is_manual = true;
                if (abs($openingDelta) > 0.0005) {
                    DB::table('stock_movements')->insert([
                        'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id,
                        'user_id' => auth()->id(), 'type' => $openingDelta > 0 ? 'adjustment_in' : 'adjustment_out',
                        'activity' => 'opening_reset', 'quantity' => $openingDelta, 'reference' => 'OPEN-'.today()->format('Ymd'),
                        'notes' => 'Koreksi stok awal oleh Admin Operasional', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            if (array_key_exists('used_quantity', $data)) {
                $usedDelta = (float) $data['used_quantity'] - (float) $record->used_quantity;
                abort_if($balance - $usedDelta < 0, 422, 'Stok terpakai melebihi sisa bahan baku.');
                $balance -= $usedDelta;
                $record->used_quantity = $data['used_quantity'];
                if (abs($usedDelta) > 0.0005) {
                    DB::table('stock_movements')->insert([
                        'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id,
                        'user_id' => auth()->id(), 'type' => $usedDelta > 0 ? 'adjustment_out' : 'adjustment_in',
                        'activity' => 'manual_usage', 'quantity' => -$usedDelta, 'reference' => 'USE-'.today()->format('Ymd'),
                        'notes' => 'Live edit stok terpakai', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            if (array_key_exists('waste_add', $data)) {
                $data['waste_quantity'] = (float) $record->waste_quantity + (float) $data['waste_add'];
            }
            if (array_key_exists('waste_quantity', $data)) {
                $wasteDelta = (float) $data['waste_quantity'] - (float) $record->waste_quantity;
                abort_if($balance - $wasteDelta < -0.0005, 422, 'Jumlah rusak melebihi sisa stok.');
                $balance -= $wasteDelta;
                $record->waste_quantity = $data['waste_quantity'];
                if (abs($wasteDelta) > 0.0005) {
                    DB::table('stock_movements')->insert([
                        'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id,
                        'user_id' => auth()->id(), 'type' => $wasteDelta > 0 ? 'adjustment_out' : 'adjustment_in',
                        'activity' => 'waste', 'quantity' => -$wasteDelta, 'reference' => 'WASTE-'.today()->format('Ymd'),
                        'notes' => 'Rusak / tidak layak'.($wasteReason ? ': '.$wasteReason : ($wasteDelta < 0 ? ' (koreksi)' : '')),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            if (array_key_exists('notes', $data)) {
                $record->notes = trim((string) ($data['notes'] ?? '')) ?: null;
            }

            $stock->quantity = max(0, $balance);
            $stock->save();
            $record->save();

            return [
                'quantity' => max(0, $balance),
                'opening_quantity' => (float) $record->opening_quantity,
                'used_quantity' => (float) $record->used_quantity,
                'waste_quantity' => (float) $record->waste_quantity,
                'notes' => $record->notes,
            ];
        });
    }

    public function reprocessStock(Request $request)
    {
        $data = $request->validate([
            'source_product_id' => 'required|integer|different:target_product_id',
            'target_product_id' => 'required|integer',
            'source_quantity' => 'required|numeric|min:0.001',
            'output_quantity' => 'required|numeric|min:0.001',
            'notes' => 'required|string|max:255',
        ]);

        DB::transaction(function () use ($data) {
            $source = Product::where('tenant_id', $this->tenantId())->where('product_type', 'menu')->findOrFail($data['source_product_id']);
            $target = Product::where('tenant_id', $this->tenantId())->where('product_type', 'menu')->findOrFail($data['target_product_id']);
            $this->menuStockForDate($source->id);
            $this->menuStockForDate($target->id);
            $sourceStock = DailyMenuStock::where('store_id', $this->storeId())->where('product_id', $source->id)->whereDate('stock_date', today())->lockForUpdate()->firstOrFail();
            $targetStock = DailyMenuStock::where('store_id', $this->storeId())->where('product_id', $target->id)->whereDate('stock_date', today())->lockForUpdate()->firstOrFail();
            abort_if((float) $sourceStock->quantity < (float) $data['source_quantity'], 422, 'Stok olahan sumber tidak mencukupi.');

            $sourceStock->decrement('quantity', $data['source_quantity']);
            $targetStock->increment('quantity', $data['output_quantity']);
            $reference = 'REPROC-'.now()->format('ymdHis').'-'.strtoupper(Str::random(2));
            foreach ([
                [$source, -(float) $data['source_quantity'], 'reprocess_out', 'Diproses ulang: '.$data['notes']],
                [$target, (float) $data['output_quantity'], 'reprocess_in', 'Hasil proses ulang: '.$data['notes']],
            ] as [$productRow, $quantity, $activity, $notes]) {
                DB::table('stock_movements')->insert([
                    'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $productRow->id,
                    'user_id' => auth()->id(), 'type' => $quantity > 0 ? 'adjustment_in' : 'adjustment_out',
                    'activity' => $activity, 'quantity' => $quantity, 'reference' => $reference,
                    'notes' => $notes, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'Proses ulang tersimpan; stok sumber dan hasil otomatis diperbarui.');
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
            $menuStock = $this->menuStockForDate($menu->id);
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
            ? $this->menuStockForDate($product->id)
            : ProductStock::where('store_id', $this->storeId())->where('product_id', $product->id)->firstOrFail();

        DB::transaction(function () use ($stock, $product, $data) {
            $expected = (float) $stock->quantity;
            $actual = (float) $data['actual_quantity'];
            StockCount::updateOrCreate(
                ['store_id' => $this->storeId(), 'product_id' => $product->id, 'count_date' => today()],
                ['tenant_id' => $this->tenantId(), 'user_id' => auth()->id(), 'expected_quantity' => $expected, 'actual_quantity' => $actual, 'notes' => $data['notes'] ?? null]
            );
            $delta = $actual - $expected;
            if (abs($delta) > 0.0005) {
                $stock->quantity = $actual;
                $stock->save();
                DB::table('stock_movements')->insert([
                    'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'product_id' => $product->id,
                    'user_id' => auth()->id(), 'type' => $delta > 0 ? 'adjustment_in' : 'adjustment_out',
                    'activity' => 'stock_opname', 'quantity' => $delta, 'reference' => 'SO-'.today()->format('Ymd'),
                    'notes' => 'Reset sesuai stok fisik'.(! empty($data['notes']) ? ': '.$data['notes'] : ''),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'Stock opname tersimpan dan saldo sistem direset ke stok fisik.');
    }

    public function purchases()
    {
        $purchases = Purchase::with('items')->where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())->latest('purchased_at')->paginate(20);
        $products = Product::where('tenant_id', $this->tenantId())->where('is_active', true)
            ->orderBy('product_type')->orderBy('name')->get();

        return $this->view('purchases.index', compact('purchases', 'products'));
    }

    public function storePurchase(Request $request)
    {
        $data = $request->validate([
            'supplier_name' => 'required|string|max:120', 'product_id' => 'required|integer', 'quantity' => 'required|numeric|min:0.001',
            'unit_cost' => 'required|numeric|min:0', 'purchased_at' => 'required|date', 'status' => ['required', Rule::in(['received', 'not_received'])],
            'payment_status' => ['required', Rule::in(['paid', 'dp', 'unpaid'])], 'dp_amount' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:255',
        ]);
        $product = Product::where('tenant_id', $this->tenantId())->where('is_active', true)->findOrFail($data['product_id']);
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

    public function updatePurchase(Request $request, Purchase $purchase)
    {
        abort_unless($purchase->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($purchase->store_id);
        $data = $request->validate([
            'supplier_name' => 'required|string|max:120', 'product_id' => 'required|integer', 'quantity' => 'required|numeric|min:0.001',
            'unit_cost' => 'required|numeric|min:0', 'purchased_at' => 'required|date', 'status' => ['required', Rule::in(['received', 'not_received'])],
            'payment_status' => ['required', Rule::in(['paid', 'dp', 'unpaid'])], 'dp_amount' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:255',
        ]);
        $product = Product::where('tenant_id', $this->tenantId())->where('is_active', true)->findOrFail($data['product_id']);
        $total = (float) $data['quantity'] * (float) $data['unit_cost'];
        abort_if(($data['dp_amount'] ?? 0) > $total, 422, 'DP tidak boleh melebihi total pembelian.');

        DB::transaction(function () use ($purchase, $product, $data, $total) {
            $locked = Purchase::where('tenant_id', $this->tenantId())->where('store_id', $this->storeId())
                ->lockForUpdate()->findOrFail($purchase->id);
            $locked->load('items');
            $wasReceived = (bool) $locked->received_at;
            $willBeReceived = $data['status'] === 'received';
            $stockDeltas = [];
            if ($wasReceived) {
                foreach ($locked->items as $item) {
                    $stockDeltas[$item->product_id] = ($stockDeltas[$item->product_id] ?? 0) - (float) $item->quantity;
                }
            }
            if ($willBeReceived) {
                $stockDeltas[$product->id] = ($stockDeltas[$product->id] ?? 0) + (float) $data['quantity'];
            }
            ksort($stockDeltas);
            foreach ($stockDeltas as $productId => $delta) {
                $this->applyPurchaseStockDelta($locked, $productId, $delta);
            }

            $locked->items()->firstOrFail()->update([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'],
                'subtotal' => $total,
            ]);
            $locked->update([
                'supplier_name' => $data['supplier_name'],
                'total' => $total,
                'status' => $willBeReceived ? 'received' : 'draft',
                'payment_status' => $data['payment_status'],
                'dp_amount' => $data['payment_status'] === 'dp' ? ($data['dp_amount'] ?? 0) : ($data['payment_status'] === 'paid' ? $total : 0),
                'received_at' => $willBeReceived ? ($locked->received_at ?? now()) : null,
                'purchased_at' => $data['purchased_at'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return back()->with('success', 'Data pembelian dan dampak stok berhasil dikoreksi.');
    }

    private function applyPurchaseStock(Purchase $purchase, int $direction): void
    {
        foreach ($purchase->items as $item) {
            $this->applyPurchaseStockDelta($purchase, $item->product_id, $direction * (float) $item->quantity);
        }
    }

    private function applyPurchaseStockDelta(Purchase $purchase, int $productId, float $delta): void
    {
        if (abs($delta) < 0.0005) {
            return;
        }
        $product = Product::withTrashed()->where('tenant_id', $this->tenantId())->findOrFail($productId);
        if ($product->product_type === 'menu') {
            $stock = $this->menuStockForDate($product->id, null, $purchase->store_id);
            $stock = DailyMenuStock::whereKey($stock->id)->lockForUpdate()->firstOrFail();
        } else {
            $stock = ProductStock::firstOrCreate([
                'tenant_id' => $this->tenantId(), 'store_id' => $purchase->store_id, 'product_id' => $productId,
            ], ['quantity' => 0]);
            $stock = ProductStock::whereKey($stock->id)->lockForUpdate()->firstOrFail();
        }
        abort_if((float) $stock->quantity + $delta < -0.0005, 422, 'Status tidak dapat diubah karena stok sudah terpakai.');
        $stock->increment('quantity', $delta);
        DB::table('stock_movements')->insert([
            'tenant_id' => $this->tenantId(), 'store_id' => $purchase->store_id, 'product_id' => $productId,
            'user_id' => auth()->id(), 'type' => $delta > 0 ? 'purchase' : 'adjustment_out',
            'activity' => $delta > 0 ? 'purchase' : 'purchase_reversal', 'quantity' => $delta,
            'reference' => $purchase->purchase_no, 'notes' => 'Perubahan stok pembelian',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function updatePurchaseStatus(Request $request, Purchase $purchase)
    {
        abort_unless($purchase->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($purchase->store_id);
        $data = $request->validate(['status' => ['required', Rule::in(['received', 'not_received'])], 'payment_status' => ['required', Rule::in(['paid', 'dp', 'unpaid'])], 'dp_amount' => 'nullable|numeric|min:0']);
        abort_if(($data['dp_amount'] ?? 0) > $purchase->total, 422, 'DP tidak boleh melebihi total pembelian.');
        DB::transaction(function () use ($purchase, $data) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
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
        $categories = Expense::CATEGORIES;

        return $this->view('expenses.index', compact('expenses', 'categories'));
    }

    public function storeExpense(Request $request)
    {
        $data = $this->expenseData($request);
        Expense::create($data + ['tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'user_id' => auth()->id(), 'report_type' => 'real']);

        return back()->with('success', 'Pengeluaran berhasil dicatat.');
    }

    private function expenseData(Request $request): array
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(Expense::CATEGORIES)],
            'description' => 'required|string|max:180',
            'amount' => 'required|numeric|min:1',
            'payment_method' => ['nullable', Rule::in(['cash', 'transfer', 'qris', 'debit'])],
            'expense_date' => 'required|date',
        ]);
        $data['payment_method'] = $data['payment_method'] ?? 'cash';

        return $data;
    }

    public function updateExpense(Request $request, Expense $expense)
    {
        abort_unless($expense->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($expense->store_id);
        $expense->update($this->expenseData($request));

        return back()->with('success', 'Pengeluaran berhasil diperbarui.');
    }

    public function destroyExpense(Expense $expense)
    {
        abort_unless($expense->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($expense->store_id);
        $expense->delete();

        return back()->with('success', 'Pengeluaran dipindahkan ke arsip.');
    }

    /** Inventaris per cabang; mode consolidated menampilkan seluruh cabang. */
    private function assetQuery(Request $request)
    {
        return InventoryAsset::with('store')->where('tenant_id', $this->tenantId())
            ->unless($this->isConsolidated(), fn ($query) => $query->where('store_id', $this->storeId()))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')->toString()))
            ->when($request->filled('condition'), fn ($query) => $request->get('condition') === 'damaged' ? $query->where('quantity_damaged', '>', 0) : $query->where('quantity_damaged', 0))
            ->when(trim($request->string('q')->toString()), fn ($query, $search) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhere('location', 'like', "%{$search}%")));
    }

    public function assets(Request $request)
    {
        $assets = $this->assetQuery($request)->orderBy('category')->orderBy('name')->paginate(25)->withQueryString();
        $summaryRows = $this->assetQuery(new Request)->get(['quantity_good', 'quantity_damaged', 'purchase_price']);
        $summary = [
            'items' => $summaryRows->count(),
            'good' => $summaryRows->sum('quantity_good'),
            'damaged' => $summaryRows->sum('quantity_damaged'),
            'value' => $summaryRows->sum(fn ($asset) => ($asset->quantity_good + $asset->quantity_damaged) * (float) $asset->purchase_price),
        ];
        $archived = InventoryAsset::onlyTrashed()->where('tenant_id', $this->tenantId())
            ->unless($this->isConsolidated(), fn ($query) => $query->where('store_id', $this->storeId()))
            ->latest('deleted_at')->take(20)->get();
        $logs = InventoryAssetLog::with(['asset', 'user'])->where('tenant_id', $this->tenantId())
            ->unless($this->isConsolidated(), fn ($query) => $query->where('store_id', $this->storeId()))
            ->latest()->take(15)->get();
        $assetStores = $this->stores();
        $categories = InventoryAsset::CATEGORIES;
        $filters = $request->only(['q', 'category', 'condition']);

        return $this->view('assets.index', compact('assets', 'summary', 'archived', 'logs', 'assetStores', 'categories', 'filters'));
    }

    private function assetData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => 'nullable|string|max:40',
            'category' => ['required', Rule::in(InventoryAsset::CATEGORIES)],
            'unit' => 'required|string|max:20',
            'quantity_good' => 'required|integer|min:0|max:100000',
            'quantity_damaged' => 'required|integer|min:0|max:100000',
            'location' => 'nullable|string|max:80',
            'purchase_date' => 'nullable|date|before_or_equal:today',
            'purchase_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'store_id' => 'nullable|integer',
        ]);
        $storeId = (int) ($data['store_id'] ?? $this->storeId());
        $this->assertStoreAccess($storeId);
        abort_unless(Store::where('tenant_id', $this->tenantId())->where('is_active', true)->whereKey($storeId)->exists(), 422, 'Cabang inventaris tidak aktif.');
        $data['store_id'] = $storeId;

        return $data;
    }

    private function logAsset(InventoryAsset $asset, string $action, string $summary): void
    {
        InventoryAssetLog::create([
            'tenant_id' => $asset->tenant_id, 'store_id' => $asset->store_id, 'inventory_asset_id' => $asset->id,
            'user_id' => auth()->id(), 'action' => $action, 'summary' => $summary,
        ]);
    }

    public function storeAsset(Request $request)
    {
        $asset = InventoryAsset::create($this->assetData($request) + ['tenant_id' => $this->tenantId(), 'user_id' => auth()->id()]);
        $this->logAsset($asset, 'created', "Ditambahkan: {$asset->quantity_good} baik, {$asset->quantity_damaged} rusak {$asset->unit}.");

        return back()->with('success', 'Inventaris '.$asset->name.' berhasil ditambahkan.');
    }

    public function updateAsset(Request $request, InventoryAsset $asset)
    {
        abort_unless($asset->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($asset->store_id);
        $data = $this->assetData($request);
        $labels = [
            'name' => 'nama', 'code' => 'kode', 'category' => 'kategori', 'unit' => 'satuan', 'quantity_good' => 'jumlah baik',
            'quantity_damaged' => 'jumlah rusak', 'location' => 'lokasi', 'purchase_date' => 'tanggal beli',
            'purchase_price' => 'harga satuan', 'notes' => 'catatan', 'store_id' => 'cabang',
        ];
        $before = $asset->only(array_keys($labels));
        $asset->update($data);
        $changes = collect($labels)->filter(function ($label, $key) use ($asset, $before) {
            $old = $before[$key] instanceof \DateTimeInterface ? $before[$key]->format('Y-m-d') : $before[$key];
            $new = $asset->{$key} instanceof \DateTimeInterface ? $asset->{$key}->format('Y-m-d') : $asset->{$key};

            return (string) $old !== (string) $new && ! (is_numeric($old) && is_numeric($new) && (float) $old === (float) $new);
        })->map(function ($label, $key) use ($asset, $before) {
            if (in_array($key, ['quantity_good', 'quantity_damaged'], true)) {
                return "{$label} {$before[$key]} → {$asset->{$key}}";
            }

            return $label;
        });
        if ($changes->isNotEmpty()) {
            $this->logAsset($asset, 'updated', 'Diubah: '.$changes->implode(', ').'.');
        }

        return back()->with('success', 'Inventaris '.$asset->name.' berhasil diperbarui.');
    }

    public function destroyAsset(InventoryAsset $asset)
    {
        abort_unless($asset->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($asset->store_id);
        $asset->delete();
        $this->logAsset($asset, 'archived', 'Diarsipkan.');

        return back()->with('success', 'Inventaris '.$asset->name.' dipindahkan ke arsip.');
    }

    public function restoreAsset(int $asset)
    {
        $asset = InventoryAsset::onlyTrashed()->where('tenant_id', $this->tenantId())->findOrFail($asset);
        $this->assertStoreAccess($asset->store_id);
        $asset->restore();
        $this->logAsset($asset, 'restored', 'Dipulihkan dari arsip.');

        return back()->with('success', 'Inventaris '.$asset->name.' berhasil dipulihkan.');
    }

    public function exportAssets(Request $request)
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Inventaris');
        $sheet->fromArray(['Cabang', 'Kode', 'Nama barang', 'Kategori', 'Lokasi', 'Baik', 'Rusak', 'Total', 'Satuan', 'Tanggal beli', 'Harga satuan', 'Nilai total', 'Catatan'], null, 'A1');
        $row = 2;
        foreach ($this->assetQuery($request)->orderBy('store_id')->orderBy('category')->orderBy('name')->get() as $asset) {
            $sheet->fromArray([[$asset->store?->name]], null, 'A'.$row);
            $sheet->setCellValueExplicit('B'.$row, (string) $asset->code, DataType::TYPE_STRING);
            $sheet->fromArray([[
                $asset->name, $asset->category, $asset->location, $asset->quantity_good, $asset->quantity_damaged, "=F{$row}+G{$row}",
                $asset->unit, $asset->purchase_date?->format('Y-m-d'), $asset->purchase_price === null ? null : (float) $asset->purchase_price,
                "=H{$row}*K{$row}", $asset->notes,
            ]], null, 'C'.$row);
            $row++;
        }
        $sheet->getStyle('A1:M1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:M'.max(1, $row - 1));
        $sheet->getStyle('K2:L'.max(2, $row - 1))->getNumberFormat()->setFormatCode('"Rp" #,##0');
        foreach (range('A', 'M') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return SpreadsheetDownload::response($spreadsheet, 'inventaris-'.now()->format('Ymd').'.xlsx');
    }

    public function members(Request $request)
    {
        $search = trim($request->string('q')->toString());
        $members = Member::where('tenant_id', $this->tenantId())->when($search, fn ($q) => $q->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('member_code', 'like', "%{$search}%")->orWhere('qr_code', $search)))->latest()->paginate(20)->withQueryString();
        $availableCards = MemberCard::where('tenant_id', $this->tenantId())->where('status', 'available')->orderBy('member_code')->get();
        $memberSummary = null;
        if (auth()->user()->canManageSystem()) {
            $memberSummary = ['count' => Member::where('tenant_id', $this->tenantId())->count(), 'deposit' => Member::where('tenant_id', $this->tenantId())->sum('deposit_balance')];
        }

        return $this->view('members.index', compact('members', 'availableCards', 'memberSummary', 'search'));
    }

    public function exportMembers()
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Database Member');
        $headers = ['Kode Member', 'QR', 'Nama', 'No. Telepon', 'Email', 'Domisili', 'Tanggal Lahir', 'Diskon (%)', 'Saldo Deposit', 'Status', 'Tanggal Daftar'];
        $sheet->fromArray($headers, null, 'A1');
        $row = 2;
        Member::where('tenant_id', $this->tenantId())->orderBy('name')->each(function (Member $member) use ($sheet, &$row) {
            $sheet->setCellValueExplicit('A'.$row, $member->member_code, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$row, $member->qr_code, DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$row, $member->name);
            $sheet->setCellValueExplicit('D'.$row, (string) $member->phone, DataType::TYPE_STRING);
            $sheet->setCellValue('E'.$row, $member->email);
            $sheet->setCellValue('F'.$row, $member->domicile);
            $sheet->setCellValue('G'.$row, $member->birth_date?->format('Y-m-d'));
            $sheet->setCellValue('H'.$row, (float) $member->discount_percent);
            $sheet->setCellValue('I'.$row, (float) $member->deposit_balance);
            $sheet->setCellValue('J'.$row, $member->is_active ? 'Aktif' : 'Nonaktif');
            $sheet->setCellValue('K'.$row, $member->created_at?->format('Y-m-d H:i'));
            $row++;
        });
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('I2:I'.max(2, $row - 1))->getNumberFormat()->setFormatCode('#,##0');
        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return SpreadsheetDownload::response($spreadsheet, 'database-member-'.now()->format('Ymd').'.xlsx');
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

    public function updateMember(Request $request, Member $member)
    {
        abort_unless($member->tenant_id === $this->tenantId(), 404);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:120',
            'domicile' => 'nullable|string|max:120',
            'birth_date' => 'nullable|date',
            'discount_percent' => 'required|numeric|min:0|max:100',
        ]);
        $member->update($data);

        return back()->with('success', 'Data member '.$member->member_code.' berhasil diperbarui.');
    }

    public function updateMemberStatus(Request $request, Member $member)
    {
        abort_unless($member->tenant_id === $this->tenantId(), 404);
        $data = $request->validate(['is_active' => 'required|boolean']);
        $member->update(['is_active' => (bool) $data['is_active']]);

        return back()->with('success', 'Status member berhasil diperbarui.');
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

    public function adjustMemberDeposit(Request $request, Member $member)
    {
        abort_unless($member->tenant_id === $this->tenantId(), 404);
        $data = $request->validate([
            'type' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => 'required|numeric|min:1',
            'reason' => 'required|string|min:5|max:255',
            'approval_pin' => 'nullable|string|min:4|max:12',
        ]);
        $authorizer = auth()->user()->isSupervisor()
            ? auth()->user()
            : $this->supervisorByPin($data['approval_pin'] ?? null);
        abort_unless($authorizer, 422, 'PIN Manager/SPV cabang aktif diperlukan untuk koreksi deposit.');

        DB::transaction(function () use ($member, $data, $authorizer) {
            $locked = Member::where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($member->id);
            $amount = (float) $data['amount'];
            abort_if($data['type'] === 'debit' && (float) $locked->deposit_balance < $amount, 422, 'Koreksi debit melebihi saldo deposit member.');
            $data['type'] === 'credit' ? $locked->increment('deposit_balance', $amount) : $locked->decrement('deposit_balance', $amount);
            DB::table('deposit_transactions')->insert([
                'tenant_id' => $this->tenantId(), 'store_id' => $this->storeId(), 'member_id' => $locked->id,
                'user_id' => auth()->id(), 'transaction_id' => null, 'type' => $data['type'], 'payment_method' => 'adjustment',
                'amount' => $amount, 'balance_after' => $locked->fresh()->deposit_balance,
                'description' => 'Koreksi deposit oleh '.$authorizer->name.': '.$data['reason'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'Koreksi deposit berhasil disimpan dalam audit trail.');
    }

    public function findMember(string $code)
    {
        $member = Member::where('tenant_id', $this->tenantId())->where('is_active', true)
            ->where(fn ($q) => $q->where('qr_code', $code)->orWhere('member_code', $code))->firstOrFail();

        return response()->json($member->only('id', 'name', 'member_code', 'deposit_balance', 'discount_percent'));
    }

    /**
     * Saldo member berlaku di semua cabang, tetapi riwayat transaksi tetap mengikuti
     * akses cabang akun: akun satu cabang hanya melihat aktivitas di cabangnya.
     */
    private function memberHistoryData(Request $request, Member $member): array
    {
        abort_unless($member->tenant_id === $this->tenantId(), 404);
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'type' => ['nullable', Rule::in(['all', 'credit', 'debit'])]]);
        $from = $request->filled('from') ? Carbon::parse($request->get('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->get('to'))->endOfDay() : null;
        $allStores = auth()->user()->canAccessAllStores();
        $scope = fn ($query, string $column) => $query
            ->unless($allStores, fn ($inner) => $inner->where($column, auth()->user()->store_id))
            ->when($from, fn ($inner) => $inner->where($column === 'store_id' ? 'transacted_at' : 'deposit_transactions.created_at', '>=', $from))
            ->when($to, fn ($inner) => $inner->where($column === 'store_id' ? 'transacted_at' : 'deposit_transactions.created_at', '<=', $to));

        $mutations = $scope(DB::table('deposit_transactions')
            ->leftJoin('stores', 'stores.id', '=', 'deposit_transactions.store_id')
            ->leftJoin('users', 'users.id', '=', 'deposit_transactions.user_id')
            ->leftJoin('transactions', 'transactions.id', '=', 'deposit_transactions.transaction_id')
            ->where('deposit_transactions.tenant_id', $this->tenantId())
            ->where('deposit_transactions.member_id', $member->id)
            ->when(in_array($request->get('type'), ['credit', 'debit'], true), fn ($query) => $query->where('deposit_transactions.type', $request->get('type'))), 'deposit_transactions.store_id')
            ->select('deposit_transactions.*', 'stores.name as store_name', 'users.name as user_name', 'transactions.invoice_no')
            ->orderByDesc('deposit_transactions.created_at')->orderByDesc('deposit_transactions.id');
        $transactions = $scope(Transaction::with(['store', 'user', 'payments', 'items'])
            ->where('tenant_id', $this->tenantId())->where('member_id', $member->id)->whereIn('status', ['completed', 'voided']), 'store_id')
            ->latest('transacted_at');
        $completed = (clone $transactions)->where('status', 'completed')->where('transaction_type', 'sale');
        $summary = [
            'spent' => (float) (clone $completed)->sum('total'),
            'visits' => (clone $completed)->count(),
            'last_visit' => (clone $completed)->max('transacted_at'),
            'topups' => (float) (clone $mutations)->where('deposit_transactions.type', 'credit')->whereNull('deposit_transactions.transaction_id')->sum('deposit_transactions.amount'),
            'deposit_used' => (float) (clone $mutations)->where('deposit_transactions.type', 'debit')->whereNotNull('deposit_transactions.transaction_id')->sum('deposit_transactions.amount'),
        ];

        return compact('mutations', 'transactions', 'summary', 'from', 'to', 'allStores');
    }

    public function memberHistory(Request $request, Member $member)
    {
        $data = $this->memberHistoryData($request, $member);
        $mutations = $data['mutations']->paginate(15, ['*'], 'mutasi')->withQueryString();
        $transactions = $data['transactions']->paginate(15, ['*'], 'trx')->withQueryString();

        return $this->view('members.history', ['member' => $member, 'mutations' => $mutations, 'transactions' => $transactions] + $data);
    }

    public function exportMemberHistory(Request $request, Member $member)
    {
        $data = $this->memberHistoryData($request, $member);
        $spreadsheet = new Spreadsheet;
        $deposit = $spreadsheet->getActiveSheet()->setTitle('Mutasi Deposit');
        $deposit->fromArray(['Waktu', 'Cabang', 'Jenis', 'Metode', 'Keterangan', 'Invoice', 'Petugas', 'Nominal', 'Saldo Setelah'], null, 'A1');
        $row = 2;
        foreach ($data['mutations']->get() as $mutation) {
            $deposit->fromArray([[
                Carbon::parse($mutation->created_at)->format('Y-m-d H:i'), $mutation->store_name, $mutation->type === 'credit' ? 'Masuk' : 'Keluar',
                strtoupper((string) $mutation->payment_method), $mutation->description, $mutation->invoice_no, $mutation->user_name,
                (float) $mutation->amount * ($mutation->type === 'credit' ? 1 : -1), (float) $mutation->balance_after,
            ]], null, 'A'.$row++);
        }
        $deposit->getStyle('H2:I'.max(2, $row - 1))->getNumberFormat()->setFormatCode('"Rp" #,##0;-"Rp" #,##0');
        $sales = $spreadsheet->createSheet()->setTitle('Transaksi');
        $sales->fromArray(['Waktu', 'Invoice', 'Cabang', 'Kasir', 'Item', 'Pembayaran', 'Status', 'Total'], null, 'A1');
        $row = 2;
        foreach ($data['transactions']->get() as $transaction) {
            $sales->fromArray([[
                $transaction->transacted_at->format('Y-m-d H:i'), $transaction->invoice_no, $transaction->store?->name, $transaction->user?->name,
                $transaction->items->map(fn ($item) => $item->product_name.' x '.Qty::format($item->quantity))->implode(', '),
                $this->paymentText($transaction), $transaction->status === 'voided' ? 'Dibatalkan' : 'Selesai', (float) $transaction->total,
            ]], null, 'A'.$row++);
        }
        $sales->getStyle('H2:H'.max(2, $row - 1))->getNumberFormat()->setFormatCode('"Rp" #,##0');
        foreach ([[$deposit, 'I'], [$sales, 'H']] as [$sheet, $last]) {
            $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            foreach (range('A', $last) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }
        $spreadsheet->setActiveSheetIndex(0);

        return SpreadsheetDownload::response($spreadsheet, 'riwayat-member-'.Str::slug($member->member_code).'-'.now()->format('Ymd').'.xlsx');
    }

    private function paymentText(Transaction $transaction): string
    {
        return $transaction->payments->isNotEmpty()
            ? $transaction->payments->map(fn ($payment) => strtoupper($payment->method).($payment->provider ? ' '.$payment->provider : ''))->implode(' + ')
            : strtoupper((string) $transaction->payment_method);
    }

    public function findAvailableMemberCard(string $code)
    {
        $card = MemberCard::where('tenant_id', $this->tenantId())->where('status', 'available')
            ->where(fn ($query) => $query->where('qr_code', $code)->orWhere('member_code', $code))->firstOrFail();

        return response()->json(['id' => $card->id, 'member_code' => $card->member_code, 'qr_code' => $card->qr_code]);
    }

    public function transactions(Request $request)
    {
        // Mode consolidated menampilkan transaksi seluruh warung; pembatalan tetap
        // dilakukan dari cabang transaksi agar stok yang dikembalikan tidak salah cabang.
        $transactions = Transaction::with(['member', 'user', 'payments', 'voidAuthorizer', 'store'])->where('tenant_id', $this->tenantId())
            ->unless($this->isConsolidated(), fn ($q) => $q->where('store_id', $this->storeId()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))->latest('transacted_at')->paginate(20)->withQueryString();
        $currentStoreId = $this->storeId();

        return $this->view('transactions.index', compact('transactions', 'currentStoreId'));
    }

    public function print(Transaction $transaction)
    {
        abort_unless($transaction->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($transaction->store_id);
        $transaction->load(['items.product', 'member', 'user', 'store', 'payments']);

        return view('transactions.print', [
            'transaction' => $transaction, 'tenant' => auth()->user()->tenant, 'receiptStore' => $transaction->store,
            'eposPrinter' => $transaction->status === 'completed' ? $this->eposPrinter($transaction->store_id)?->eposConfig() : null,
            'rawbtPrinter' => $transaction->status === 'completed' ? $this->rawbtPrinter($transaction->store_id) : null,
        ]);
    }

    public function destroyTransaction(Request $request, Transaction $transaction)
    {
        abort_unless($transaction->tenant_id === $this->tenantId() && $transaction->store_id === $this->storeId(), 404);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255', 'approval_pin' => 'nullable|string|max:12']);
        DB::transaction(function () use ($transaction, $data) {
            // Re-read after acquiring the lock: route binding may predate another refund.
            $transaction = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            abort_unless($transaction->status === 'completed', 422, 'Hanya transaksi selesai yang dapat dibatalkan.');
            $needsApproval = $transaction->transacted_at->diffInSeconds(now()) > 30;
            $authorizer = $needsApproval ? $this->supervisorByPin($data['approval_pin'] ?? null) : auth()->user();
            abort_if($needsApproval && ! $authorizer, 422, 'Transaksi lebih dari 30 detik memerlukan PIN Manager/SPV yang valid.');
            $transaction->load(['items', 'payments', 'member']);
            foreach ($transaction->items->sortBy('product_id') as $item) {
                if (! $item->product_id) {
                    continue;
                }
                // Saldo operasional berjalan ada pada record hari ini. Mengubah record tanggal
                // transaksi lama tidak akan mengembalikan stok yang sedang dipakai kasir.
                $stock = $this->menuStockForDate($item->product_id);
                $stock->increment('quantity', $item->quantity);
                DB::table('stock_movements')->insert(['tenant_id' => $this->tenantId(), 'store_id' => $transaction->store_id, 'product_id' => $item->product_id, 'user_id' => auth()->id(), 'type' => 'adjustment_in', 'activity' => 'void_reversal', 'quantity' => $item->quantity, 'reference' => $transaction->invoice_no, 'notes' => 'Pembatalan: '.$data['reason'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $deposit = $transaction->payments->where('method', 'deposit')->sum('amount');
            if ($deposit <= 0 && $transaction->payment_method === 'deposit') {
                $deposit = $transaction->total;
            }
            if ($deposit > 0 && $transaction->member) {
                $transaction->setRelation('member', Member::whereKey($transaction->member_id)->lockForUpdate()->firstOrFail());
                $transaction->member->increment('deposit_balance', $deposit);
                DB::table('deposit_transactions')->insert(['tenant_id' => $this->tenantId(), 'store_id' => $transaction->store_id, 'member_id' => $transaction->member->id, 'user_id' => auth()->id(), 'transaction_id' => $transaction->id, 'type' => 'credit', 'payment_method' => 'deposit', 'amount' => $deposit, 'balance_after' => $transaction->member->fresh()->deposit_balance, 'description' => 'Refund pembatalan '.$transaction->invoice_no, 'created_at' => now(), 'updated_at' => now()]);
            }
            $transaction->update(['status' => 'voided', 'cancel_reason' => $data['reason'], 'void_authorized_by' => $authorizer?->id, 'voided_at' => now()]);
            // DP reservasi kembali tersedia; reservasi dapat dibuka lagi di Kasir.
            Reservation::where('transaction_id', $transaction->id)->where('status', 'completed')
                ->update(['transaction_id' => null, 'status' => 'arrived', 'dp_used' => 0, 'closed_at' => null]);
        }, 3);

        return back()->with('success', 'Transaksi dibatalkan, stok, deposit, dan DP reservasi terkait telah dipulihkan.');
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
        ['sales' => $sales, 'cost' => $cost, 'expenses' => $expenses, 'tax' => $tax, 'service' => $service, 'profit' => $profit, 'daily' => $daily, 'payments' => $payments, 'transactions' => $transactionRows, 'products' => $productSales, 'newMembers' => $newMembers, 'topups' => $topups, 'depositUsed' => $depositUsed, 'turnoverNetDeposit' => $turnoverNetDeposit, 'storeComparison' => $storeComparison] = $data;

        return $this->view('reports.index', compact('type', 'factor', 'percentage', 'period', 'from', 'to', 'sales', 'cost', 'expenses', 'tax', 'service', 'profit', 'daily', 'payments', 'transactionRows', 'productSales', 'newMembers', 'topups', 'depositUsed', 'turnoverNetDeposit', 'storeComparison', 'canSeeNonReal'));
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

        return SpreadsheetDownload::response($spreadsheet, $filename);
    }

    private function roles()
    {
        return Role::where('tenant_id', $this->tenantId())->where('key', '!=', User::DEVELOPER)
            ->orderBy('position')->orderBy('name')->get();
    }

    public function settings()
    {
        $canManageSystem = auth()->user()->canManageSystem();
        $settingPermissions = collect(array_keys(Role::SETTINGS_PERMISSIONS))
            ->mapWithKeys(fn ($permission) => [$permission => auth()->user()->canManageSetting($permission)]);
        $users = $canManageSystem
            ? User::withTrashed()->where('tenant_id', $this->tenantId())->where('role', '!=', User::DEVELOPER)->orderBy('name')->get()
            : collect();
        $roles = $canManageSystem ? $this->roles() : collect();
        $userCountPerRole = $canManageSystem
            ? User::where('tenant_id', $this->tenantId())->where('role', '!=', User::DEVELOPER)->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role')
            : collect();
        // Developer/Superadmin dapat membuat akun, tetapi tidak dapat menugaskan role sistem.
        $creatableRoles = $canManageSystem
            ? $roles->where('is_system', false)->pluck('name', 'key')
            : collect();
        $settingsStore = $this->activeStoreRecord();
        $devices = $settingPermissions['devices']
            ? ConnectedDevice::where('tenant_id', $this->tenantId())->with('store')
                ->where(fn ($query) => $query->whereNull('store_id')->orWhere('store_id', $settingsStore->id))->get()
            : collect();
        $cards = $settingPermissions['member_cards']
            ? MemberCard::where('tenant_id', $this->tenantId())->where('status', 'available')->latest()->take(20)->get()
            : collect();
        $stores = ($settingPermissions['branches'] || $settingPermissions['devices'] || $canManageSystem)
            ? Store::where('tenant_id', $this->tenantId())
                ->unless(auth()->user()->canAccessAllStores(), fn ($query) => $query->whereKey($this->storeId()))
                ->orderBy('name')->get()
            : collect();

        return $this->view('settings.index', ['tenant' => auth()->user()->tenant, 'settingsStore' => $settingsStore, 'stores' => $stores, 'users' => $users, 'roles' => $roles, 'userCountPerRole' => $userCountPerRole, 'creatableRoles' => $creatableRoles, 'canManageRoles' => $canManageSystem, 'settingPermissions' => $settingPermissions, 'devices' => $devices, 'cards' => $cards]);
    }

    private function roleData(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'summary' => ['nullable', 'string', 'max:120'],
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(array_keys(Role::MODULES))],
            'settings_permissions' => ['nullable', 'array'],
            'settings_permissions.*' => [Rule::in(array_keys(Role::SETTINGS_PERMISSIONS))],
            'can_see_non_real' => ['nullable', 'boolean'],
            'is_supervisor' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => $data['name'],
            'summary' => $data['summary'] ?? null,
            'modules' => Role::sanitizeModules($data['modules']),
            'settings_permissions' => in_array('settings', $data['modules'], true)
                ? Role::sanitizeSettingsPermissions($data['settings_permissions'] ?? [])
                : [],
            'can_see_non_real' => $request->boolean('can_see_non_real'),
            'is_supervisor' => $request->boolean('is_supervisor'),
            // Akses lintas warung tidak dibuka lewat form; lihat catatan pada view Pengaturan.
            'can_access_all_stores' => $role?->can_access_all_stores ?? false,
        ];
    }

    public function storeRole(Request $request)
    {
        abort_unless(auth()->user()->canManageSystem(), 403);
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
        abort_unless(auth()->user()->canManageSystem(), 403);
        abort_unless($role->tenant_id === $this->tenantId(), 404);
        abort_if($role->is_system, 422, 'Hak akses role sistem tidak dapat diubah.');
        $role->update($this->roleData($request, $role));
        auth()->user()->forgetRoleDefinition();

        return back()->with('success', 'Hak akses '.$role->name.' berhasil diperbarui.');
    }

    public function destroyRole(Role $role)
    {
        abort_unless(auth()->user()->canManageSystem(), 403);
        abort_unless($role->tenant_id === $this->tenantId(), 404);
        abort_if($role->is_system, 422, 'Role sistem tidak dapat dihapus.');
        abort_if($role->users()->exists(), 422, 'Role masih dipakai akun aktif. Pindahkan akunnya terlebih dahulu.');
        $role->delete();

        return back()->with('success', 'Role '.$role->name.' dihapus.');
    }

    public function updateBrand(Request $request)
    {
        abort_unless(auth()->user()->canManageSetting('branding'), 403);
        $store = $this->requestedSettingsStore($request);
        $data = $request->validate(['business_name' => 'required|string|max:120', 'logo' => 'nullable|image|max:2048']);
        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('branding', 'public');
        }
        unset($data['logo']);
        $store->update($data);

        return back()->with('success', 'Identitas '.$store->name.' berhasil diperbarui.');
    }

    public function updateReceiptSettings(Request $request)
    {
        abort_unless(auth()->user()->canManageSetting('receipt'), 403);
        $store = $this->requestedSettingsStore($request);
        $data = $request->validate(['receipt_header' => 'nullable|string|max:120', 'receipt_footer' => 'nullable|string|max:180']);
        $data['receipt_show_logo'] = $request->boolean('receipt_show_logo');
        $data['receipt_sort_by_category'] = $request->boolean('receipt_sort_by_category');
        $store->update($data);

        return back()->with('success', 'Tampilan struk '.$store->name.' berhasil diperbarui.');
    }

    public function updateBusinessRules(Request $request)
    {
        abort_unless(auth()->user()->canManageSetting('business_rules'), 403);
        $store = $this->requestedSettingsStore($request);
        $data = $request->validate([
            'non_real_percentage' => 'required|numeric|min:0|max:100',
            'member_discount_percent' => 'required|numeric|min:0|max:100',
        ]);
        $store->update($data);

        return back()->with('success', 'Aturan laporan dan membership '.$store->name.' berhasil diperbarui.');
    }

    public function updateTaxService(Request $request)
    {
        abort_unless(auth()->user()->canManageSetting('tax_service'), 403);
        $store = $this->requestedSettingsStore($request);
        $types = array_keys(Store::SERVICE_TYPES);
        $data = $request->validate([
            'service_charge_percent' => 'required|numeric|min:0|max:100',
            'service_charge_types' => ['nullable', 'array'],
            'service_charge_types.*' => [Rule::in($types)],
            'tax_percent' => 'required|numeric|min:0|max:100',
            'tax_label' => 'required|string|max:30',
            'tax_types' => ['nullable', 'array'],
            'tax_types.*' => [Rule::in($types)],
        ]);
        $store->update([
            'service_charge_percent' => $data['service_charge_percent'],
            'service_charge_types' => array_values(array_intersect($types, $data['service_charge_types'] ?? [])),
            'tax_percent' => $data['tax_percent'],
            'tax_label' => trim($data['tax_label']),
            'tax_types' => array_values(array_intersect($types, $data['tax_types'] ?? [])),
        ]);

        return back()->with('success', 'Pajak & service '.$store->name.' berhasil diperbarui.');
    }

    public function storeBranch(Request $request)
    {
        abort_unless(auth()->user()->canManageSetting('branches'), 403);
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
            'service_charge_percent' => $source->service_charge_percent,
            'service_charge_types' => $source->service_charge_types,
            'tax_percent' => $source->tax_percent,
            'tax_label' => $source->tax_label,
            'tax_types' => $source->tax_types,
        ]);

        return back()->with('success', 'Cabang baru berhasil ditambahkan.');
    }

    public function updateBranch(Request $request, Store $store)
    {
        abort_unless(auth()->user()->canManageSetting('branches'), 403);
        abort_unless($store->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($store->id);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => ['required', 'string', 'max:20', Rule::unique('stores')->where('tenant_id', $this->tenantId())->ignore($store->id)],
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
        ]);
        $store->update($data);

        return back()->with('success', 'Data cabang berhasil diperbarui.');
    }

    public function updateBranchStatus(Request $request, Store $store)
    {
        abort_unless(auth()->user()->canManageSetting('branches'), 403);
        abort_unless($store->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($store->id);
        $isActive = (bool) $request->validate(['is_active' => 'required|boolean'])['is_active'];
        if (! $isActive) {
            abort_if(User::where('tenant_id', $this->tenantId())->where('store_id', $store->id)->where('is_active', true)->exists(), 422, 'Cabang masih memiliki pengguna aktif. Pindahkan atau nonaktifkan akun terlebih dahulu.');
            abort_if(Store::where('tenant_id', $this->tenantId())->where('is_active', true)->count() <= 1, 422, 'Cabang aktif terakhir tidak dapat dinonaktifkan.');
        }
        $store->update(['is_active' => $isActive]);

        return back()->with('success', 'Status cabang berhasil diperbarui.');
    }

    public function storeDevice(Request $request)
    {
        abort_unless(auth()->user()->canManageSetting('devices'), 403);
        $data = $this->deviceData($request);
        if (! empty($data['store_id'])) {
            abort_unless(Store::where('tenant_id', $this->tenantId())->where('is_active', true)->whereKey($data['store_id'])->exists(), 422);
            $this->assertStoreAccess((int) $data['store_id']);
        }
        $this->assertStoreAccess(isset($data['store_id']) ? (int) $data['store_id'] : null);
        ConnectedDevice::create($data + ['tenant_id' => $this->tenantId(), 'status' => 'active']);

        return back()->with('success', 'Perangkat berhasil ditambahkan.');
    }

    /** Printer Epson ePOS menyimpan alamat IP dan opsi cetak; perangkat lain cukup nama & koneksi. */
    private function deviceData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'type' => ['required', Rule::in(['receipt_printer', 'cash_drawer', 'barcode_scanner', 'customer_display', 'other'])],
            'driver' => ['nullable', Rule::in(['generic', ConnectedDevice::DRIVER_EPSON_EPOS, ConnectedDevice::DRIVER_RAWBT])],
            'connection' => 'nullable|string|max:120',
            'store_id' => 'nullable|integer',
            'epos_host' => ['nullable', 'required_if:driver,'.ConnectedDevice::DRIVER_EPSON_EPOS, 'string', 'max:120', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'epos_port' => 'nullable|integer|min:1|max:65535',
            'epos_device_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'epos_timeout' => 'nullable|integer|min:3000|max:60000',
            'epos_paper' => ['nullable', Rule::in(['58', '80'])],
            'epos_columns' => 'nullable|integer|min:24|max:64',
        ], [], ['epos_host' => 'alamat IP printer']);
        $epson = ($data['driver'] ?? 'generic') === ConnectedDevice::DRIVER_EPSON_EPOS && $data['type'] === 'receipt_printer';
        $rawbt = ($data['driver'] ?? 'generic') === ConnectedDevice::DRIVER_RAWBT && $data['type'] === 'receipt_printer';
        $device = [
            'name' => $data['name'], 'type' => $data['type'], 'store_id' => $data['store_id'] ?? null,
            'driver' => $epson ? ConnectedDevice::DRIVER_EPSON_EPOS : ($rawbt ? ConnectedDevice::DRIVER_RAWBT : 'generic'),
            'connection' => $epson ? $data['epos_host'] : ($rawbt ? 'RawBT Android / Bluetooth' : ($data['connection'] ?? null)),
            'settings' => null,
        ];
        if ($rawbt) {
            $device['settings'] = ['paper' => 58, 'columns' => 32];
        }
        if ($epson) {
            $device['settings'] = [
                'host' => $data['epos_host'],
                'port' => $data['epos_port'] ?? null,
                'https' => $request->boolean('epos_https'),
                'device_id' => $data['epos_device_id'] ?? 'local_printer',
                'timeout' => (int) ($data['epos_timeout'] ?? 10000),
                'paper' => (int) ($data['epos_paper'] ?? 80),
                'columns' => $data['epos_columns'] ?? null,
                'auto_print' => $request->boolean('epos_auto_print'),
                'kitchen_copy' => $request->boolean('epos_kitchen_copy'),
                'open_drawer' => $request->boolean('epos_open_drawer'),
            ];
        }

        return $device;
    }

    public function updateDevice(Request $request, ConnectedDevice $device)
    {
        abort_unless(auth()->user()->canManageSetting('devices'), 403);
        abort_unless($device->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($device->store_id);
        $data = $this->deviceData($request);
        if (! empty($data['store_id'])) {
            abort_unless(Store::where('tenant_id', $this->tenantId())->where('is_active', true)->whereKey($data['store_id'])->exists(), 422);
        }
        $this->assertStoreAccess(isset($data['store_id']) ? (int) $data['store_id'] : null);
        $device->update($data);

        return back()->with('success', 'Perangkat '.$device->name.' berhasil diperbarui.');
    }

    public function destroyDevice(ConnectedDevice $device)
    {
        abort_unless(auth()->user()->canManageSetting('devices'), 403);
        abort_unless($device->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($device->store_id);
        $device->delete();

        return back()->with('success', 'Perangkat dilepas.');
    }

    public function testDevice(ConnectedDevice $device)
    {
        abort_unless(auth()->user()->canManageSetting('devices'), 403);
        abort_unless($device->tenant_id === $this->tenantId(), 404);
        $this->assertStoreAccess($device->store_id);
        abort_unless(filled($device->connection), 422, 'Alamat/koneksi perangkat belum diisi.');
        $device->update(['last_tested_at' => now(), 'status' => 'active']);
        if (request()->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Konfigurasi perangkat valid dan waktu pengecekan dicatat. Koneksi fisik tetap perlu diuji dari komputer kasir.');
    }

    public function storeUser(Request $request)
    {
        abort_unless(auth()->user()->canManageSystem(), 403);
        // Role diambil dari master, kecuali role sistem yang tidak boleh ditugaskan ke akun baru.
        $assignable = Role::where('tenant_id', $this->tenantId())->where('is_system', false)->pluck('key')->all();
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|unique:users,email', 'role' => ['required', Rule::in($assignable)], 'store_id' => 'required|integer', 'password' => 'required|string|min:8', 'authorization_pin' => 'nullable|digits_between:4,8']);
        abort_unless(Store::where('tenant_id', $this->tenantId())->where('is_active', true)->whereKey($data['store_id'])->exists(), 422);
        if (! empty($data['authorization_pin'])) {
            $data['authorization_pin'] = Hash::make($data['authorization_pin']);
        }
        User::create($data + ['tenant_id' => $this->tenantId(), 'password' => Hash::make($data['password']), 'is_active' => true]);

        return back()->with('success', 'Pengguna baru berhasil ditambahkan.');
    }

    public function updateUser(Request $request, User $user)
    {
        abort_unless(auth()->user()->canManageSystem() && $user->tenant_id === $this->tenantId(), 403);
        abort_if($user->role === User::DEVELOPER && ! auth()->user()->isDeveloper(), 403);
        $assignable = Role::where('tenant_id', $this->tenantId())->where('is_system', false)->pluck('key')->all();
        if ($user->role === User::DEVELOPER && auth()->user()->isDeveloper()) {
            $assignable[] = User::DEVELOPER;
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in($assignable)],
            'store_id' => 'required|integer',
            'password' => 'nullable|string|min:8',
            'authorization_pin' => 'nullable|digits_between:4,8',
        ]);
        abort_unless(Store::where('tenant_id', $this->tenantId())->where('is_active', true)->whereKey($data['store_id'])->exists(), 422);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        if (filled($data['authorization_pin'] ?? null)) {
            $data['authorization_pin'] = Hash::make($data['authorization_pin']);
        } else {
            unset($data['authorization_pin']);
        }
        $user->update($data);

        return back()->with('success', 'Akun pengguna berhasil diperbarui.');
    }

    public function updateUserStatus(Request $request, int $user)
    {
        $user = User::withTrashed()->where('tenant_id', $this->tenantId())->findOrFail($user);
        abort_unless(auth()->user()->canManageSystem() && $user->id !== auth()->id(), 403);
        abort_if($user->role === User::DEVELOPER && ! auth()->user()->isDeveloper(), 403);
        $isActive = (bool) $request->validate(['is_active' => 'required|boolean'])['is_active'];
        if ($isActive) {
            $user->restore();
            $user->update(['is_active' => true]);
        } else {
            $user->update(['is_active' => false]);
            $user->delete();
        }

        return back()->with('success', 'Status akun pengguna berhasil diperbarui.');
    }

    public function destroyUser(User $user)
    {
        abort_unless(auth()->user()->canManageSystem() && $user->tenant_id === $this->tenantId() && $user->id !== auth()->id(), 403);
        abort_if($user->role === User::DEVELOPER && ! auth()->user()->isDeveloper(), 403);
        $user->update(['is_active' => false]);
        $user->delete();

        return back()->with('success', 'Akun pengguna dinonaktifkan.');
    }

    public function updateUserPin(Request $request, User $user)
    {
        abort_unless(auth()->user()->canManageSystem() && $user->tenant_id === $this->tenantId(), 403);
        abort_if($user->role === User::DEVELOPER && ! auth()->user()->isDeveloper(), 403);
        abort_unless($user->isSupervisor(), 422, 'PIN otorisasi hanya untuk Manager/SPV.');
        $pin = $request->validate(['authorization_pin' => 'required|digits_between:4,8'])['authorization_pin'];
        $user->update(['authorization_pin' => Hash::make($pin)]);

        return back()->with('success', 'PIN otorisasi '.$user->name.' berhasil diperbarui.');
    }

    public function precreateMemberCards(Request $request)
    {
        abort_unless(auth()->user()->canManageSetting('member_cards'), 403);
        $count = $request->validate(['count' => 'required|integer|min:1|max:100'])['count'];
        for ($i = 0; $i < $count; $i++) {
            $next = (MemberCard::where('tenant_id', $this->tenantId())->max('id') ?? 0) + 1;
            MemberCard::create(['tenant_id' => $this->tenantId(), 'member_code' => 'MBR-P'.str_pad($next, 5, '0', STR_PAD_LEFT), 'qr_code' => (string) Str::uuid(), 'status' => 'available']);
        }

        return back()->with('success', $count.' kartu QR siap dicetak dan diaktivasi saat customer mendaftar.');
    }

    public function runMaintenance(Request $request)
    {
        abort_unless(auth()->user()->canRunMaintenance(), 403);
        $data = $request->validate(['command' => ['required', Rule::in(['migrate', 'optimize_clear', 'storage_link'])]]);
        $commands = [
            'migrate' => ['command' => 'migrate', 'parameters' => ['--force' => true], 'label' => 'Migrasi database'],
            'optimize_clear' => ['command' => 'optimize:clear', 'parameters' => [], 'label' => 'Bersihkan cache'],
            'storage_link' => ['command' => 'storage:link', 'parameters' => [], 'label' => 'Hubungkan storage'],
        ];
        $selected = $commands[$data['command']];
        if ($data['command'] === 'storage_link' && File::exists(public_path('storage'))) {
            return back()->with('success', 'Storage publik sudah terhubung.')->with('maintenance_output', 'Tautan public/storage sudah tersedia. Tidak ada perubahan yang diperlukan.');
        }
        try {
            $exitCode = Artisan::call($selected['command'], $selected['parameters']);
            $output = trim(Artisan::output()) ?: 'Perintah selesai tanpa keluaran tambahan.';
            if ($exitCode !== 0) {
                return back()->withErrors(['maintenance' => $selected['label'].' gagal dijalankan.'])->with('maintenance_output', $output);
            }

            return back()->with('success', $selected['label'].' berhasil dijalankan.')->with('maintenance_output', $output);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['maintenance' => $selected['label'].' gagal dijalankan. Periksa log aplikasi.']);
        }
    }
}
