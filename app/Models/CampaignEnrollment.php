<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignEnrollment extends Model
{
    protected $guarded = [];
    protected $casts = [
        'next_run_at' => 'datetime', 'enrolled_at' => 'datetime', 'delivered_at' => 'datetime',
        'replied_at' => 'datetime', 'won_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
