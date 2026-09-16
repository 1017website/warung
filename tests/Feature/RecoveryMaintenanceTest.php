<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RecoveryMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryStorage = sys_get_temp_dir().'/warung-recovery-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->temporaryStorage.'/app', 0755, true);
        File::makeDirectory($this->temporaryStorage.'/framework', 0755, true);
        $this->app->useStoragePath($this->temporaryStorage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryStorage);
        parent::tearDown();
    }

    public function test_guests_and_staff_cannot_run_commands(): void
    {
        Artisan::shouldReceive('call')->never();
        $this->get('/maintenance/migrate')->assertRedirect('/login');
        $this->actingAs(new User(['role' => User::CASHIER, 'is_active' => true]))
            ->getJson('/maintenance/migrate')->assertForbidden();
    }

    public function test_superadmin_can_run_allowed_commands_before_setup_is_complete(): void
    {
        $this->actingAs(new User(['role' => User::SUPERADMIN, 'is_active' => true]));
        foreach (['migrate' => 'migrate', 'optimize-clear' => 'optimize:clear', 'storage-link' => 'storage:link'] as $route => $command) {
            Artisan::shouldReceive('call')->once()->with($command, $route === 'migrate' ? ['--force' => true] : [])->andReturn(0);
            Artisan::shouldReceive('output')->once()->andReturn('Done');
            $this->getJson('/maintenance/'.$route)->assertOk()->assertJson(['exit_code' => 0]);
        }
        $this->getJson('/maintenance/migrate-fresh')->assertNotFound();
        $this->postJson('/maintenance/migrate')->assertStatus(405);
    }

    public function test_command_failure_returns_error_status(): void
    {
        $this->actingAs(new User(['role' => User::DEVELOPER, 'is_active' => true]));
        Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('Failed');
        $this->getJson('/maintenance/migrate')->assertStatus(500)->assertJson(['exit_code' => 1]);
    }
}
