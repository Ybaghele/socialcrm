<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');                         // Growth Plan
            $table->string('slug')->unique();
            $table->unsignedBigInteger('price_paise');      // 499900 = ₹4,999
            $table->string('billing_interval')->default('monthly');
            $table->unsignedInteger('gst_percent')->default(18);
            $table->unsignedInteger('monthly_active_contacts'); // 2500
            $table->unsignedInteger('overage_block_size')->default(500);
            $table->unsignedBigInteger('overage_price_paise')->default(150000); // ₹1,500 / block
            $table->unsignedInteger('max_instagram_accounts');  // 5
            $table->unsignedInteger('max_agent_seats');         // 5
            $table->string('razorpay_plan_id')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('active'); // trialing|active|past_due|cancelled
            $table->string('razorpay_subscription_id')->nullable()->unique();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable(); // "Renews Oct 12, 2026"
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
