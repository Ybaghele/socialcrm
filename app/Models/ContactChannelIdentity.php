<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactChannelIdentity extends Model
{
    protected $guarded = [];
    protected $casts = ['last_inbound_at' => 'datetime'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function channelAccount(): BelongsTo { return $this->belongsTo(ChannelAccount::class); }
}
