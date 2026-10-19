<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "SocialDesk Activity Log" in the lead dossier.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = automation
            $table->string('event');                     // campaign_triggered|tag_added|assigned|stage_changed|resolved
            $table->string('description');
            $table->nullableMorphs('subject');           // Campaign, Tag, Conversation...
            $table->json('properties')->nullable();      // {"from":"New Inquiry","to":"Hot Lead"}
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contact_id', 'created_at']);
            $table->index(['workspace_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
