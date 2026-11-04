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
        Schema::table('ad_campaigns', function (Blueprint $table) {
            // 1-based wizard step the operator last saved, so a draft reopens
            // where they left off instead of jumping back to Platform.
            $table->integer('current_step')->default(1)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropColumn('current_step');
        });
    }
};
