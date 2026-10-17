<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Billing quota: a contact counts once per billing period no matter
        // how many messages they exchange ("1,420 / 2,500 Active Leads").
        Schema::create('monthly_active_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');                // first day of billing period
            $table->timestamp('first_active_at')->useCurrent();

            $table->unique(['workspace_id', 'contact_id', 'period_start'], 'mac_unique');
            $table->index(['workspace_id', 'period_start']);
        });

        // Raw inbound Meta payloads: store first, process on queue, dedupe on retries.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');                  // meta | razorpay | shopify
            $table->string('event_type')->nullable();    // messages | statuses | comments | payment.captured
            $table->string('external_id')->nullable();   // provider event id
            $table->json('payload');
            $table->string('status')->default('pending'); // pending | processed | failed | ignored
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('monthly_active_contacts');
    }
};
