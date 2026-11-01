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
        Schema::create('publish_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_campaign_id')->nullable()->constrained('ad_campaigns')->nullOnDelete();
            $table->string('platform')->default('meta');
            $table->string('status'); // success | failed
            $table->string('step')->nullable(); // connection | campaign | ad_set | ad_creative | ad | complete
            $table->string('error_code')->nullable();
            $table->string('error_subcode')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_user_title')->nullable();
            $table->text('error_user_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('trace')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['ad_campaign_id', 'created_at']);
        });

        Schema::table('ad_creatives', function (Blueprint $table) {
            // Meta's creative wrapper id, kept so a re-publish reuses the asset
            // instead of creating a duplicate creative.
            $table->string('platform_creative_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_creatives', function (Blueprint $table) {
            $table->dropColumn('platform_creative_id');
        });

        Schema::dropIfExists('publish_logs');
    }
};
