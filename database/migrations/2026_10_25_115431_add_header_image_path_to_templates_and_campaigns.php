<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            // The sample image submitted to Meta. Synced templates may not have one.
            $table->string('header_image_path')->nullable()->after('components');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            // Per-campaign image for an image-header template. Falls back to the
            // template's sample image when the operator does not upload one.
            $table->string('header_image_path')->nullable()->after('template_variables');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('header_image_path');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('header_image_path');
        });
    }
};
