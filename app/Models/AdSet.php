<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdSet extends Model
{
    /** @use HasFactory<\Database\Factories\AdSetFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'audience_json' => 'array',
        'placements_json' => 'array',
        'budget_amount' => 'decimal:2',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'campaign_id');
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(AdCreative::class, 'ad_set_id');
    }
}
