<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Message extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];
    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function template(): BelongsTo { return $this->belongsTo(MessageTemplate::class, 'message_template_id'); }
    public function attachments(): HasMany { return $this->hasMany(MessageAttachment::class); }

    public function getIsInternalAttribute(): bool { return $this->direction === 'internal'; }
}
