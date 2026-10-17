<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One thread per contact per channel account (inbox list row).
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel');                   // denormalised: filter tabs
            $table->string('status')->default('open');   // open | resolved
            $table->unsignedInteger('unread_count')->default(0);
            $table->text('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();  // drives 24h window
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contact_id', 'channel_account_id']);
            $table->index(['workspace_id', 'status', 'last_message_at']);
            $table->index(['workspace_id', 'channel', 'status']);
            $table->index(['workspace_id', 'assigned_user_id', 'status']);
        });

        // Customer messages, agent replies, bot messages AND private internal notes.
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction');                 // inbound | outbound | internal
            $table->string('sender_type');               // contact | user | bot | system
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // author
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('text');     // text|image|video|audio|document|template|interactive|story_reply|comment
            $table->text('body')->nullable();
            $table->json('payload')->nullable();         // buttons, location, raw Meta payload
            $table->string('external_id')->nullable();   // wamid.* / mid.* for dedupe + status webhooks
            $table->string('status')->default('sent');   // queued|sent|delivered|read|failed
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('cost_paise')->default(0); // template fee billed to wallet
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['workspace_id', 'campaign_id']);
            $table->unique(['workspace_id', 'external_id']); // idempotent webhook processing
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('type');                      // image|video|audio|document|sticker
            $table->string('disk')->default('s3');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('external_media_id')->nullable(); // Meta media id
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
