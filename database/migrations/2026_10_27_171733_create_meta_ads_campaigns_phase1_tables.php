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
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('platform')->default('meta');
            $table->string('ad_account_id')->nullable();
            $table->string('name');
            $table->string('objective')->nullable();
            $table->string('special_category')->nullable();
            $table->string('budget_type')->nullable(); // daily|lifetime
            $table->decimal('budget_amount', 12, 2)->nullable();
            $table->string('status')->default('draft');
            $table->string('platform_campaign_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('ad_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->string('name');
            $table->json('audience_json')->nullable();
            $table->json('placements_json')->nullable();
            $table->string('optimization_goal')->nullable();
            $table->decimal('budget_amount', 12, 2)->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('platform_adset_id')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->index(['campaign_id']);
        });

        Schema::create('ad_creatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_set_id')->constrained('ad_sets')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('format')->nullable(); // image|video|carousel
            $table->string('media_url')->nullable();
            $table->text('primary_text')->nullable();
            $table->string('headline')->nullable();
            $table->text('description')->nullable();
            $table->string('cta')->nullable();
            $table->string('destination_url')->nullable();
            $table->string('platform_ad_id')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->index(['ad_set_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_creatives');
        Schema::dropIfExists('ad_sets');
        Schema::dropIfExists('ad_campaigns');
    }
};
