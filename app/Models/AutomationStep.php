<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One timed message inside a follow-up sequence.
 *
 * Steps carry a template rather than free text on purpose: a sequence fires hours
 * or days after the last inbound message, so WhatsApp's 24-hour reply window has
 * closed and only an approved template may be sent.
 */
class AutomationStep extends Model
{
    protected $guarded = [];

    protected $casts = [
        'template_variables' => 'array',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }
}
