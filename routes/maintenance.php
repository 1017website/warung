<?php

use App\Http\Controllers\RecoveryMaintenanceController;
use Illuminate\Support\Facades\Route;

Route::get('/maintenance/{command}', RecoveryMaintenanceController::class)
    ->middleware(['web', 'auth'])
    ->where('command', 'migrate|optimize-clear|storage-link')
    ->name('maintenance.recovery');
