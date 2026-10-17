<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};

class WalletTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }
    public function reference(): MorphTo { return $this->morphTo(); }
}
