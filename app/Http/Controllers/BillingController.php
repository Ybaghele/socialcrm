<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET  /w/{workspace}/billing          plan, quota, add-ons, wallet & invoices
 * POST /w/{workspace}/billing/top-up   add broadcast credit to the wallet
 *
 * Everything is read from the real billing tables (plans, subscriptions,
 * monthly_active_contacts, wallets, wallet_transactions, payments).
 *
 * NOTE ON RAZORPAY: the SDK is not installed yet, so `topUp` captures the
 * payment manually and then credits the wallet through the one service allowed
 * to touch a balance (WalletService), writing the same append-only ledger line a
 * gateway webhook would. As soon as `services.razorpay.key` is set, the manual
 * path disables itself (501) so nobody can mint balance once a real gateway is live.
 */
class BillingController extends Controller
{
    /** Preset top-up amounts, in rupees, offered by the "Add Balance" modal. */
    private const TOP_UP_PRESETS = [1000, 2500, 5000];

    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);
        $limits = PlanLimits::for($workspace);
        $plan = $limits->plan();
        $subscription = $workspace->subscription;

        $used = $limits->activeContactsUsed();
        $contactLimit = $plan?->monthly_active_contacts;
        $overageBlocks = $limits->overageBlocks();

        $wallet = Wallet::where('workspace_id', $workspace->id)->first();

        return Inertia::render('Billing', [
            'plan' => $plan ? [
                'name' => $plan->name,
                'slug' => $plan->slug,
                'pricePaise' => (int) $plan->price_paise,
                'gstPercent' => (int) $plan->gst_percent,
                'billingInterval' => $plan->billing_interval,
                'overagePricePaise' => (int) $plan->overage_price_paise,
                'overageBlockSize' => (int) $plan->overage_block_size,
            ] : null,
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'onTrial' => $subscription->status === 'trialing',
                'trialEndsAt' => $subscription->trial_ends_at?->toIso8601String(),
                'currentPeriodStart' => $subscription->current_period_start?->toIso8601String(),
                'currentPeriodEnd' => $subscription->current_period_end?->toIso8601String(),
            ] : null,
            'usage' => [
                'used' => $used,
                'limit' => $contactLimit,
                'percent' => $contactLimit ? min(100, (int) round($used / $contactLimit * 100)) : 0,
                'overageBlocks' => $overageBlocks,
                'overagePaise' => $overageBlocks * (int) ($plan?->overage_price_paise ?? 0),
            ],
            'addons' => [
                'instagram' => [
                    'used' => $workspace->channelAccounts()->where('channel', 'instagram')->count(),
                    'max' => $plan?->max_instagram_accounts,
                ],
                'seats' => [
                    'used' => $limits->seatsUsed(),
                    'max' => $plan?->max_agent_seats,
                ],
                'channels' => [
                    'used' => $workspace->channelAccounts()->count(),
                ],
            ],
            'wallet' => [
                'balancePaise' => (int) ($wallet?->balance_paise ?? 0),
                'estimatedMessages' => (int) ($wallet?->estimated_messages ?? 0),
                'perMessagePaise' => (int) config('omniflow.pricing.whatsapp_template_paise.marketing', 88),
            ],
            'transactions' => WalletTransaction::where('workspace_id', $workspace->id)
                ->latest('created_at')
                ->limit(15)
                ->get()
                ->map(fn (WalletTransaction $tx) => [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'reason' => $tx->reason,
                    'amountPaise' => (int) $tx->amount_paise,
                    'balanceAfterPaise' => (int) $tx->balance_after_paise,
                    'description' => $tx->description,
                    'createdAt' => $tx->created_at?->toIso8601String(),
                ])
                ->all(),
            'payments' => Payment::query()
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'invoiceNumber' => $payment->invoice_number,
                    'purpose' => $payment->purpose,
                    'amountPaise' => (int) $payment->amount_paise,
                    'gstPaise' => (int) $payment->gst_paise,
                    'status' => $payment->status,
                    'method' => $payment->method,
                    'paidAt' => $payment->paid_at?->toIso8601String(),
                    'createdAt' => $payment->created_at?->toIso8601String(),
                ])
                ->all(),
            'topUpPresets' => self::TOP_UP_PRESETS,
            'gateway' => [
                'configured' => (bool) config('services.razorpay.key'),
            ],
        ]);
    }

    public function topUp(Request $request, WalletService $wallet): RedirectResponse
    {
        $workspace = $this->workspace($request);

        // Once a real gateway is configured, balance may only be minted by its
        // webhook — not by this request — so refuse here.
        abort_if(config('services.razorpay.key'), 501, 'Razorpay checkout is not wired up yet.');

        $data = $request->validate([
            // Rupees, whole numbers only: ₹100 .. ₹5,00,000.
            'amount' => ['required', 'integer', 'min:100', 'max:500000'],
        ]);

        $amountPaise = $data['amount'] * 100;
        $gstPaise = (int) round($amountPaise * 0.18);

        $payment = DB::transaction(function () use ($workspace, $request, $amountPaise, $gstPaise, $wallet) {
            $payment = Payment::create([
                'user_id' => $request->user()->id,
                'subscription_id' => $workspace->subscription?->id,
                'purpose' => 'wallet_top_up',
                'amount_paise' => $amountPaise,
                'gst_paise' => $gstPaise,
                'status' => 'captured',
                'method' => 'upi',
                'razorpay_order_id' => 'manual_order_'.Str::random(16),
                'razorpay_payment_id' => 'manual_pay_'.Str::random(16),
                'meta' => ['gateway' => 'manual', 'note' => 'Captured without a live Razorpay gateway.'],
                'paid_at' => now(),
            ]);

            $payment->forceFill([
                'invoice_number' => sprintf('INV-%s-%05d', now()->format('Y'), $payment->id),
            ])->save();

            $wallet->credit($workspace, $amountPaise, 'top_up', $payment, 'WhatsApp broadcast balance top-up');

            return $payment;
        });

        return back()->with(
            'success',
            'Wallet topped up by ₹'.number_format($data['amount']).' — invoice '.$payment->invoice_number.'.',
        );
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        return $workspace;
    }
}
