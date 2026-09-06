<?php

namespace App\Http\Controllers;

use App\Models\{Category, DailyMenuStock, Product, Role, Store, Tenant, User};
use App\Services\InitialSetup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SetupController extends Controller
{
    public function show(Request $request, InitialSetup $setup)
    {
        $user = $request->user();
        if (! $setup->required($user)) {
            return redirect()->route($user->landingRoute());
        }
        $tenant = Tenant::find($user->tenant_id);
        $store = Store::where('tenant_id', $user->tenant_id)->where('is_active', true)->first();
        $step = (int) ($tenant?->setup_step ?? 1);
        $products = $tenant ? Product::where('tenant_id', $tenant->id)->where('is_active', true)->where('product_type', 'menu')->get() : collect();
        return view('setup', compact('user', 'tenant', 'store', 'step', 'products'));
    }

    public function save(Request $request, InitialSetup $setup)
    {
        abort_unless($request->user()->canManageSystem(), 403);
        $request->validate(['step' => ['required', 'integer', 'between:1,3']]);

        DB::transaction(function () use ($request, $setup) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $tenant = Tenant::whereKey($user->tenant_id)->lockForUpdate()->first();
            if (! $setup->required($user)) {
                return;
            }
            $step = (int) ($tenant?->setup_step ?? 1);
            // A retried request or an old browser tab must not repeat a completed step.
            if ((int) $request->input('step') !== $step) {
                return;
            }
            if ($step === 1) {
                $data = $request->validate([
                    'business_name' => ['required', 'string', 'max:255'],
                    'store_name' => ['required', 'string', 'max:255'],
                    'address' => ['required', 'string', 'max:255'],
                    'phone' => ['nullable', 'string', 'max:30'],
                    'receipt_footer' => ['nullable', 'string', 'max:255'],
                    'member_discount_percent' => ['required', 'numeric', 'between:0,100'],
                ]);
                if (! $tenant) {
                    $tenant = Tenant::create(['name' => $data['business_name'], 'slug' => 'usaha-'.Str::uuid(), 'setup_step' => 1]);
                }
                $tenant->update(['name' => $data['business_name'], 'member_discount_percent' => $data['member_discount_percent'], 'receipt_footer' => $data['receipt_footer'] ?? null, 'setup_step' => 2]);
                $store = Store::where('tenant_id', $tenant->id)->where('is_active', true)->first();
                $values = ['name' => $data['store_name'], 'business_name' => $data['business_name'], 'address' => $data['address'], 'phone' => $data['phone'] ?? null, 'receipt_footer' => $data['receipt_footer'] ?? null, 'member_discount_percent' => $data['member_discount_percent']];
                if ($store) {
                    $store->update($values);
                } else {
                    $store = Store::create($values + ['tenant_id' => $tenant->id, 'code' => 'CB-'.Str::upper(Str::random(8)), 'is_active' => true]);
                }
                // Only supply missing defaults; existing role customizations are preserved.
                foreach (Role::DEFAULTS as $position => $role) {
                    Role::withTrashed()->firstOrCreate(['tenant_id' => $tenant->id, 'key' => $role['key']], $role + ['position' => $position]);
                }
                $user->update(['tenant_id' => $tenant->id, 'store_id' => $store->id]);
                $request->session()->put('store_id', $store->id);
            } elseif ($step === 2) {
                $data = $request->validate([
                    'category' => ['required', 'string', 'max:255'],
                    'product_name' => ['required', 'string', 'max:255'],
                    'unit' => ['required', 'string', 'max:20'],
                    'selling_price' => ['required', 'numeric', 'min:1', 'max:999999999999'],
                    'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999', 'decimal:0,3'],
                ]);
                $store = Store::where('tenant_id', $tenant->id)->whereKey($user->store_id)->where('is_active', true)->firstOrFail();
                $category = Category::withTrashed()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => $data['category']]);
                if ($category->trashed()) $category->restore();
                $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'sku' => 'MENU-'.Str::upper(Str::random(12)), 'name' => $data['product_name'], 'product_type' => 'menu', 'unit' => $data['unit'], 'selling_price' => $data['selling_price'], 'online_selling_price' => $data['selling_price'], 'is_active' => true]);
                DailyMenuStock::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $product->id, 'stock_date' => today(), 'quantity' => $data['quantity']]);
                DB::table('stock_movements')->insert(['created_at' => now(), 'updated_at' => now(), 'tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $product->id, 'user_id' => $user->id, 'type' => 'adjustment_in', 'quantity' => $data['quantity'], 'reference' => 'SETUP', 'notes' => 'Stok menu awal dari wizard setup']);
                $tenant->update(['setup_step' => 3]);
            } else {
                $request->validate(['confirm' => ['accepted']]);
                if (! Product::where('tenant_id', $tenant->id)->where('product_type', 'menu')->where('is_active', true)->where('selling_price', '>', 0)->exists() || ! $user->roleDefinition() || empty($user->menu())) {
                    throw ValidationException::withMessages(['setup' => 'Data produk atau hak akses belum lengkap. Hubungi pengelola sistem.']);
                }
                $tenant->update(['setup_step' => null]);
            }
        });
        $request->user()->refresh()->forgetRoleDefinition();
        return redirect()->route('setup');
    }
}


