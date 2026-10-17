<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphMany};

class Payment extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];
    protected $casts = ['meta' => 'array', 'paid_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function walletTransactions(): MorphMany { return $this->morphMany(WalletTransaction::class, 'reference'); }
}
