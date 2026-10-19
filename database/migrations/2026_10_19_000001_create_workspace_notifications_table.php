<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notifications, one row per recipient. Fanning out at write time
        // keeps read/unread state naturally per-user and keeps the header query a
        // single indexed count.
        Schema::create('workspace_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');                        // campaign_completed | wallet_low | template_approved | member_joined
            $table->string('level', 16)->default('info');  // info | success | warning | danger
            $table->string('title');
            $table->string('body')->nullable();
            $table->string('url')->nullable();             // where clicking it should go
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'read_at']);
            $table->index(['workspace_id', 'user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_notifications');
    }
};
