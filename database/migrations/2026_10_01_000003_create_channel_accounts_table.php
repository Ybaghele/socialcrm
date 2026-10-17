<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per connected WhatsApp number / Instagram account / Facebook Page.
        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('channel');                  // whatsapp | instagram | messenger
            $table->string('name');                     // display name
            $table->string('external_id');              // phone_number_id / IG user id / page id
            $table->string('handle')->nullable();       // @brand or +91 number
            $table->string('business_account_id')->nullable(); // WABA id / FB business id
            $table->text('access_token')->nullable();   // cast as 'encrypted' on the model
            $table->timestamp('token_expires_at')->nullable();
            $table->string('webhook_verify_token')->nullable();
            $table->string('status')->default('connected'); // connected | disconnected | error
            $table->timestamp('last_webhook_at')->nullable();
            $table->json('meta')->nullable();           // quality rating, tier limit, etc.
            $table->timestamps();

            $table->unique(['channel', 'external_id']); // webhooks resolve the account from this
            $table->index(['workspace_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_accounts');
    }
};
