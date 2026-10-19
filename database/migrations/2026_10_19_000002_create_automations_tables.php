<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One rule per row, whatever its flavour:
        //   auto_reply — answer an inbound message (keywords / first message / out of hours)
        //   routing    — claim the thread for an agent (round-robin / least busy / a person)
        //   sequence   — follow up a quiet thread with timed template steps
        // `conditions` is the trigger, `actions` the response; both are JSON so a new
        // condition never needs a migration. Steps live in automation_steps.
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');                            // auto_reply | routing | sequence
            $table->string('status')->default('active');       // active | paused
            $table->unsignedSmallInteger('priority')->default(0); // lower runs first
            $table->json('conditions');
            $table->json('actions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'type', 'status', 'priority']);
        });

        // The numbered messages a follow-up sequence sends, and how long to wait
        // before each one. Steps are templates: a sequence fires hours or days
        // later, when the free-form 24h window has certainly closed.
        Schema::create('automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->json('template_variables')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'position']);
        });

        // One row per (automation, contact). That single uniqueness does three jobs:
        // it dedupes an auto-reply, it stops a routing rule re-claiming a thread, and
        // for a sequence it is the progress record (which step, when next).
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active');       // active | completed | cancelled
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'contact_id']);
            $table->index(['workspace_id', 'status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_steps');
        Schema::dropIfExists('automations');
    }
};
