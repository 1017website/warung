<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RecoveryMaintenanceController extends Controller
{
    public function __invoke(Request $request, string $command): JsonResponse
    {
        abort_unless($request->user()->is_active && $request->user()->canRunMaintenance(), 403);

        $commands = [
            'migrate' => ['migrate', ['--force' => true]],
            'optimize-clear' => ['optimize:clear', []],
            'storage-link' => ['storage:link', []],
        ];
        abort_unless(isset($commands[$command]), 404);

        // A file lock works even before database-backed cache tables exist.
        $lock = fopen(storage_path('framework/maintenance-recovery.lock'), 'c');
        if ($lock === false) {
            return response()->json(['message' => 'File lock maintenance tidak dapat dibuat.'], 500);
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return response()->json(['message' => 'Maintenance sedang berjalan.'], 409);
        }

        try {
            [$artisanCommand, $parameters] = $commands[$command];
            $exitCode = Artisan::call($artisanCommand, $parameters);

            return response()->json([
                'command' => $artisanCommand,
                'exit_code' => $exitCode,
                'output' => trim(Artisan::output()),
            ], $exitCode === 0 ? 200 : 500)->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Maintenance gagal. Periksa storage/logs/laravel.log.'], 500);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
