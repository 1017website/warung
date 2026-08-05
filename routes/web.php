<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WarungController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route(in_array(auth()->user()->role, ['superadmin', 'head_ops', 'owner', 'admin']) ? 'dashboard' : 'pos'));
    Route::post('/switch-store', [WarungController::class, 'switchStore'])->name('stores.switch');

    Route::middleware('role:superadmin,head_ops,owner,admin')->group(function () {
        Route::get('/dashboard', [WarungController::class, 'dashboard'])->name('dashboard');
    });

    Route::middleware('role:superadmin,head_ops,ops_admin,owner,admin,cashier,spv,outlet_manager')->group(function () {
        Route::get('/kasir', [WarungController::class, 'pos'])->name('pos');
        Route::post('/kasir/checkout', [WarungController::class, 'checkout'])->name('pos.checkout');
        Route::post('/kasir/pending', [WarungController::class, 'holdBill'])->name('pos.pending');
        Route::post('/kasir/custom-amount', [WarungController::class, 'toggleCustomAmount'])->name('pos.custom-amount');
        Route::get('/kasir/tutup-harian', [WarungController::class, 'closeCashier'])->name('pos.close');
        Route::get('/transaksi', [WarungController::class, 'transactions'])->name('transactions');
        Route::get('/transaksi/{transaction}/print', [WarungController::class, 'print'])->name('transactions.print');
        Route::delete('/transaksi/{transaction}', [WarungController::class, 'destroyTransaction'])->name('transactions.destroy');
        Route::get('/member/find/{code}', [WarungController::class, 'findMember'])->name('members.find');
        Route::get('/member/card/{code}', [WarungController::class, 'findAvailableMemberCard'])->name('members.card');
        Route::get('/member', [WarungController::class, 'members'])->name('members');
        Route::post('/member', [WarungController::class, 'storeMember'])->name('members.store');
        Route::post('/member/{member}/topup', [WarungController::class, 'topup'])->name('members.topup');
    });

    Route::middleware('role:superadmin,head_ops,ops_admin,owner,admin,warehouse')->group(function () {
        Route::get('/produk', [WarungController::class, 'products'])->name('products');
        Route::post('/produk', [WarungController::class, 'storeProduct'])->name('products.store');
        Route::put('/produk/{product}', [WarungController::class, 'updateProduct'])->name('products.update');
        Route::delete('/produk/{product}', [WarungController::class, 'destroyProduct'])->name('products.destroy');
        Route::get('/produk/export', [WarungController::class, 'exportProducts'])->name('products.export');
        Route::post('/produk/import', [WarungController::class, 'importProducts'])->name('products.import');
        Route::get('/pembelian', [WarungController::class, 'purchases'])->name('purchases');
        Route::post('/pembelian', [WarungController::class, 'storePurchase'])->name('purchases.store');
        Route::patch('/pembelian/{purchase}/status', [WarungController::class, 'updatePurchaseStatus'])->name('purchases.status');
    });

    Route::middleware('role:superadmin,head_ops,ops_admin,owner,admin,warehouse,cashier,spv,outlet_manager')->group(function () {
        Route::get('/gudang', [WarungController::class, 'inventory'])->name('inventory');
        Route::post('/gudang/adjust', [WarungController::class, 'adjustStock'])->name('inventory.adjust');
        Route::post('/gudang/production', [WarungController::class, 'storeProduction'])->name('inventory.production');
        Route::post('/gudang/count', [WarungController::class, 'storeStockCount'])->name('inventory.count');
        Route::get('/pengeluaran', [WarungController::class, 'expenses'])->name('expenses');
        Route::post('/pengeluaran', [WarungController::class, 'storeExpense'])->name('expenses.store');
        Route::delete('/pengeluaran/{expense}', [WarungController::class, 'destroyExpense'])->name('expenses.destroy');
    });

    Route::middleware('role:superadmin,head_ops,owner,admin')->group(function () {
        Route::get('/laporan', [WarungController::class, 'reports'])->name('reports');
        Route::get('/laporan/export', [WarungController::class, 'exportReport'])->name('reports.export');
    });

    Route::middleware('role:superadmin,admin')->group(function () {
        Route::get('/pengaturan', [WarungController::class, 'settings'])->name('settings');
        Route::post('/pengaturan/brand', [WarungController::class, 'updateBrand'])->name('settings.brand');
        Route::post('/pengaturan/receipt', [WarungController::class, 'updateReceiptSettings'])->name('settings.receipt');
        Route::post('/pengaturan/aturan-bisnis', [WarungController::class, 'updateBusinessRules'])->name('settings.business-rules');
        Route::post('/pengaturan/cabang', [WarungController::class, 'storeBranch'])->name('settings.branch');
        Route::post('/pengaturan/perangkat', [WarungController::class, 'storeDevice'])->name('settings.device');
        Route::delete('/pengaturan/perangkat/{device}', [WarungController::class, 'destroyDevice'])->name('settings.device.destroy');
        Route::post('/pengaturan/pengguna', [WarungController::class, 'storeUser'])->name('settings.user');
        Route::patch('/pengaturan/pengguna/{user}/pin', [WarungController::class, 'updateUserPin'])->name('settings.user.pin');
        Route::delete('/pengaturan/pengguna/{user}', [WarungController::class, 'destroyUser'])->name('settings.user.destroy');
        Route::post('/pengaturan/member-card', [WarungController::class, 'precreateMemberCards'])->name('settings.member-card');
        Route::post('/pengaturan/pemeliharaan', [WarungController::class, 'runMaintenance'])->name('settings.maintenance');
    });
});
