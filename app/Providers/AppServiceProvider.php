<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
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
        // Pagination bawaan Laravel memakai Tailwind dengan mode gelap otomatis yang tidak cocok dengan tema aplikasi.
        Paginator::defaultView('partials.pagination');
    }
}
