<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // comment_to_dm | broadcast | ad_click_welcome | webhook_flow
            $table->string('type');
            $table->string('channel');                  // whatsapp | instagram | messenger
            $table->string('status')->default('draft'); // draft|scheduled|active|paused|completed|failed
            $table->text('message_body')->nullable();   // supports {{name}} merge tags
            $table->json('audience_description')->nullable(); // "All Reels & Feed Posts"
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            // Denormalised counters shown on the campaign cards (updated by queue jobs).
            $table->unsignedInteger('enrolled_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('replied_count')->default(0);
            $table->unsignedInteger('won_count')->default(0);
            $table->unsignedBigInteger('cost_paise')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
        });

        // What starts a campaign: keyword in a comment, Shopify webhook, ad click...
        Schema::create('campaign_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('type');                    // comment_keyword | message_keyword | webhook | ad_click | manual
            $table->string('match_type')->default('contains'); // contains | exact | regex
            $table->json('keywords')->nullable();      // ["PRICE","OFFER"]
            $table->json('scope')->nullable();         // {"post_ids":[...]} or {"all_posts":true}
            $table->json('conditions')->nullable();    // e.g. cart idle > 2h, cart value > 2000
            $table->timestamps();

            $table->index(['campaign_id', 'type']);
        });

        // Sequence steps: message, wait, quick-reply qualification, route to agent...
        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type');                    // message | template | delay | condition | assign_agent | add_tag | set_stage
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->text('body')->nullable();
            $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->json('config')->nullable();        // quick-reply buttons, branch rules, tag/stage ids
            $table->timestamps();

            $table->unique(['campaign_id', 'position']);
        });

        // A contact moving through a campaign.
        Schema::create('campaign_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active'); // active|completed|exited|failed
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->string('trigger_ref')->nullable();   // IG comment id → one DM per comment
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id']);
            $table->unique(['campaign_id', 'trigger_ref']);
            $table->index(['status', 'next_run_at']);    // scheduler polls this
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_enrollments');
        Schema::dropIfExists('campaign_steps');
        Schema::dropIfExists('campaign_triggers');
        Schema::dropIfExists('campaigns');
    }
};
