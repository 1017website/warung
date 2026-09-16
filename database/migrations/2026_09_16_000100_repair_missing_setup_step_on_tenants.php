<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'setup_step')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->unsignedTinyInteger('setup_step')->nullable();
            });
        }
    }

    public function down(): void
    {
        // The original migration owns this column; rolling back this repair must preserve it.
    }
};
