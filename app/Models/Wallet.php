<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Wallet extends Model
{
    protected $guarded = [];

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function transactions(): HasMany { return $this->hasMany(WalletTransaction::class)->latest('created_at'); }

    // Always lock the row inside a DB transaction before debiting/crediting.
    public function getEstimatedMessagesAttribute(): int { return intdiv($this->balance_paise, 88); } // ₹0.88 each
}
