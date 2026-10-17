<?php

namespace App\Services\Billing;

use App\Exceptions\InsufficientBalance;
use App\Models\Message;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only code allowed to change a wallet balance.
 * Row-locks the wallet inside a transaction and writes an append-only ledger line each time.
 */
class WalletService
{
    public function debit(Workspace $workspace, int $amountPaise, string $reason, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        return $this->apply($workspace, 'debit', $amountPaise, $reason, $reference, $description);
    }

    public function credit(Workspace $workspace, int $amountPaise, string $reason, ?Model $reference = null, ?string $description = null): WalletTransaction
    {
        return $this->apply($workspace, 'credit', $amountPaise, $reason, $reference, $description);
    }

    /** Give a message's charge back, at most once, however many failure signals arrive. */
    public function refundMessage(Message $message): ?WalletTransaction
    {
        if ($message->cost_paise <= 0) {
            return null;
        }

        $workspace = Workspace::findOrFail($message->workspace_id);

        return DB::transaction(function () use ($workspace, $message) {
            Wallet::where('workspace_id', $workspace->id)->lockForUpdate()->firstOrFail(); // serialise concurrent refunds

            $alreadyRefunded = WalletTransaction::where('reference_type', $message->getMorphClass())
                ->where('reference_id', $message->getKey())
                ->where('reason', 'refund')
                ->exists();

            return $alreadyRefunded
                ? null
                : $this->credit($workspace, $message->cost_paise, 'refund', $message, 'Refund for undelivered message');
        });
    }

    private function apply(Workspace $workspace, string $type, int $amount, string $reason, ?Model $reference, ?string $description): WalletTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Wallet amounts must be positive paise.');
        }

        return DB::transaction(function () use ($workspace, $type, $amount, $reason, $reference, $description) {
            $wallet = Wallet::where('workspace_id', $workspace->id)->lockForUpdate()->firstOrFail();

            $balance = $type === 'debit' ? $wallet->balance_paise - $amount : $wallet->balance_paise + $amount;

            if ($balance < 0) {
                throw new InsufficientBalance();
            }

            $wallet->update(['balance_paise' => $balance]);

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'workspace_id' => $workspace->id,
                'type' => $type,
                'amount_paise' => $amount,
                'balance_after_paise' => $balance,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'description' => $description,
            ]);
        });
    }
}
