<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Subscription extends Model
{
    protected $guarded = [];
    protected $casts = [
        'trial_ends_at' => 'datetime', 'current_period_start' => 'datetime',
        'current_period_end' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
}
