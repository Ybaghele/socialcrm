<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};

class Conversation extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];
    protected $casts = [
        'last_message_at' => 'datetime', 'last_inbound_at' => 'datetime',
        'first_response_at' => 'datetime', 'resolved_at' => 'datetime',
    ];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function channelAccount(): BelongsTo { return $this->belongsTo(ChannelAccount::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
    public function messages(): HasMany { return $this->hasMany(Message::class)->oldest(); }
    public function latestMessage(): HasOne { return $this->hasOne(Message::class)->latestOfMany(); }

    // WhatsApp/IG/Messenger only allow free-form replies within 24h of the last inbound message.
    public function isWithinReplyWindow(): bool
    {
        return $this->last_inbound_at?->gt(now()->subDay()) ?? false;
    }
}
