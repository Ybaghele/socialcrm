<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single automation rule. The three types share this table because they share
 * their shape — a JSON trigger (`conditions`), a JSON response (`actions`) and a
 * priority — and because they are edited on one screen.
 *
 *   auto_reply  conditions: keywords[], only_first_message, outside_business_hours, channels[]
 *               actions:    reply, assign_user_id
 *   routing     conditions: outside_business_hours, channels[]
 *               actions:    strategy (round_robin = fewest conversations, least_busy = fewest open), user_ids[], user_id
 *   sequence    conditions: no_reply_after_hours, channels[]
 *               actions:    (steps live in automation_steps)
 */
class Automation extends Model
{
    use BelongsToWorkspace;

    public const TYPE_AUTO_REPLY = 'auto_reply';

    public const TYPE_ROUTING = 'routing';

    public const TYPE_SEQUENCE = 'sequence';

    /** @var array<int, string> */
    public const TYPES = [self::TYPE_AUTO_REPLY, self::TYPE_ROUTING, self::TYPE_SEQUENCE];

    protected $guarded = [];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'last_run_at' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class)->orderBy('position');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<Automation> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** @param Builder<Automation> $query */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
