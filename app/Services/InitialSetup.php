<?php

namespace App\Services;

use App\Models\{Role, Store, Tenant, User};

class InitialSetup
{
    public function required(User $user): bool
    {
        $tenant = Tenant::find($user->tenant_id);
        return ! $tenant || $tenant->setup_step !== null
            || ! Store::where('tenant_id', $tenant->id)->where('is_active', true)->exists()
            || ! Role::where('tenant_id', $tenant->id)->exists();
    }
}
