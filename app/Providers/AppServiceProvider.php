<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // @qty($value): kuantitas tanpa nol desimal, mis. 2 bukan 2.000.
        Blade::directive('qty', fn (string $expression) => '<?php echo e(\App\Support\Qty::format('.$expression.')); ?>');
    }
}
