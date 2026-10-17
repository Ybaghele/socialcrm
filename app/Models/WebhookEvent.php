<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];

    public function channelAccount(): BelongsTo { return $this->belongsTo(ChannelAccount::class); }
}
