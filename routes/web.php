<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WarungController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', [WarungController::class, 'dashboard'])->name('dashboard');
    Route::post('/switch-store', [WarungController::class, 'switchStore'])->name('stores.switch');

    Route::middleware('role:superadmin,owner,admin,cashier')->group(function () {
        Route::get('/kasir', [WarungController::class, 'pos'])->name('pos');
        Route::post('/kasir/checkout', [WarungController::class, 'checkout'])->name('pos.checkout');
        Route::get('/transaksi', [WarungController::class, 'transactions'])->name('transactions');
        Route::get('/transaksi/{transaction}/print', [WarungController::class, 'print'])->name('transactions.print');
        Route::get('/member/find/{code}', [WarungController::class, 'findMember'])->name('members.find');
    });

    Route::middleware('role:superadmin,owner,admin,warehouse')->group(function () {
        Route::get('/produk', [WarungController::class, 'products'])->name('products');
        Route::post('/produk', [WarungController::class, 'storeProduct'])->name('products.store');
        Route::put('/produk/{product}', [WarungController::class, 'updateProduct'])->name('products.update');
        Route::delete('/produk/{product}', [WarungController::class, 'destroyProduct'])->name('products.destroy');
        Route::get('/gudang', [WarungController::class, 'inventory'])->name('inventory');
        Route::post('/gudang/adjust', [WarungController::class, 'adjustStock'])->name('inventory.adjust');
        Route::get('/pembelian', [WarungController::class, 'purchases'])->name('purchases');
        Route::post('/pembelian', [WarungController::class, 'storePurchase'])->name('purchases.store');
    });

    Route::middleware('role:superadmin,owner,admin')->group(function () {
        Route::delete('/transaksi/{transaction}', [WarungController::class, 'destroyTransaction'])->name('transactions.destroy');
        Route::get('/pengeluaran', [WarungController::class, 'expenses'])->name('expenses');
        Route::post('/pengeluaran', [WarungController::class, 'storeExpense'])->name('expenses.store');
        Route::delete('/pengeluaran/{expense}', [WarungController::class, 'destroyExpense'])->name('expenses.destroy');
        Route::get('/member', [WarungController::class, 'members'])->name('members');
        Route::post('/member', [WarungController::class, 'storeMember'])->name('members.store');
        Route::post('/member/{member}/topup', [WarungController::class, 'topup'])->name('members.topup');
        Route::get('/laporan', [WarungController::class, 'reports'])->name('reports');
        Route::get('/laporan/export', [WarungController::class, 'exportReport'])->name('reports.export');
        Route::get('/pengaturan', [WarungController::class, 'settings'])->name('settings');
        Route::post('/pengaturan/brand', [WarungController::class, 'updateBrand'])->name('settings.brand');
        Route::post('/pengaturan/cabang', [WarungController::class, 'storeBranch'])->name('settings.branch');
        Route::post('/pengaturan/pengguna', [WarungController::class, 'storeUser'])->name('settings.user');
        Route::post('/pengaturan/pemeliharaan', [WarungController::class, 'runMaintenance'])->name('settings.maintenance');
    });
});
