<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meta-approved WhatsApp templates (needed for broadcasts outside the 24h window).
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('language', 10)->default('en');
            $table->string('category');                // marketing | utility | authentication
            $table->string('status')->default('pending'); // pending | approved | rejected | paused
            $table->string('meta_template_id')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->json('components');                // header/body/footer/buttons as sent to Meta
            $table->timestamps();

            $table->unique(['channel_account_id', 'name', 'language']);
        });

        // Quick Templates / canned replies in the inbox composer.
        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('shortcut')->nullable();    // "/shipping"
            $table->timestamps();

            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_responses');
        Schema::dropIfExists('message_templates');
    }
};
