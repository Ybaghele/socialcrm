<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Local pause switch. Meta's status stays the source of truth for approval;
        // this only decides whether the tenant can still pick the template.
        Schema::table('message_templates', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
