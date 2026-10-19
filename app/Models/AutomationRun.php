<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One automation acting on one contact — the dedupe key that stops an auto-reply
 * storming, a routing rule re-claiming a thread every message, and a sequence
 * enrolling the same person twice.
 *
 * For a sequence it is also the progress record: `current_step` is the next step
 * to send and `next_run_at` is when.
 *
 * Not tenant-scoped: the follow-up processor sweeps every workspace, and each row
 * already pins `workspace_id` explicitly.
 */
class AutomationRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_sent_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
