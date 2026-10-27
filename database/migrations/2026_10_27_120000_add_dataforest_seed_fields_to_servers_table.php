<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'dataforest_seed_id')) {
                // dataforest identifies a seed by UUID, not by a numeric id.
                $table->string('dataforest_seed_id')->nullable()->after('hostinger_virtual_machine_status');
            }

            if (! Schema::hasColumn('servers', 'dataforest_seed_status')) {
                $table->string('dataforest_seed_status')->nullable()->after('dataforest_seed_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'dataforest_seed_status')) {
                $table->dropColumn('dataforest_seed_status');
            }

            if (Schema::hasColumn('servers', 'dataforest_seed_id')) {
                $table->dropColumn('dataforest_seed_id');
            }
        });
    }
};
