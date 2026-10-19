<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Ordered values for the chosen WhatsApp template's {{1}}, {{2}}, … placeholders.
            // Each value may itself contain a merge tag ({{name}}, {{phone}}) that the
            // delivery job expands per contact before handing it to Meta.
            $table->json('template_variables')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('template_variables');
        });
    }
};
