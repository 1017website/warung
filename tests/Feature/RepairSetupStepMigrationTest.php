<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepairSetupStepMigrationTest extends TestCase
{
    private const REPAIR = '2026_09_16_000100_repair_missing_setup_step_on_tenants';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        app('migration.repository')->createRepository();
        app('migration.repository')->log('2026_09_06_000100_add_setup_step_to_tenants', 5);
    }

    public function test_recorded_original_migration_does_not_prevent_repairing_missing_column(): void
    {
        DB::table('tenants')->insert(['name' => 'Existing']);
        $this->assertFalse(Schema::hasColumn('tenants', 'setup_step'));

        $this->artisan('migrate', ['--path' => 'database/migrations/'.self::REPAIR.'.php', '--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('tenants', 'setup_step'));
        $this->assertDatabaseHas('tenants', ['name' => 'Existing', 'setup_step' => null]);
        $this->assertDatabaseHas('migrations', ['migration' => self::REPAIR]);
        DB::table('tenants')->insert(['name' => 'New business', 'setup_step' => 1]);
        $this->assertDatabaseHas('tenants', ['name' => 'New business', 'setup_step' => 1]);
    }

    public function test_existing_setup_progress_survives_repair_retries_and_rollback(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->unsignedTinyInteger('setup_step')->nullable());
        DB::table('tenants')->insert(['name' => 'In progress', 'setup_step' => 2]);
        $migration = require database_path('migrations/'.self::REPAIR.'.php');
        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertDatabaseHas('tenants', ['name' => 'In progress', 'setup_step' => 2]);
    }
}
