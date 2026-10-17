<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prepaid WhatsApp wallet. Money is ALWAYS integer paise, never float.
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('balance_paise')->default(0);
            $table->string('currency', 3)->default('INR');
            $table->timestamps();
        });

        // Append-only ledger. Never update or delete rows.
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type');                      // credit | debit
            $table->unsignedBigInteger('amount_paise');
            $table->bigInteger('balance_after_paise');
            $table->string('reason');                    // top_up | template_message | refund | adjustment
            $table->nullableMorphs('reference');         // Payment, Message, Campaign
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose');                   // wallet_top_up | subscription | overage
            $table->unsignedBigInteger('amount_paise');
            $table->unsignedBigInteger('gst_paise')->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('status')->default('created'); // created|authorized|captured|failed|refunded
            $table->string('method')->nullable();        // upi | card | netbanking
            $table->string('razorpay_order_id')->nullable()->index();
            $table->string('razorpay_payment_id')->nullable()->unique();
            $table->string('invoice_number')->nullable()->unique();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
