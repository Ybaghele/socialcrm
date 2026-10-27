<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdCampaign extends Model
{
    /** @use HasFactory<\Database\Factories\AdCampaignFactory> */
    use HasFactory, BelongsToWorkspace, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'budget_amount' => 'decimal:2',
    ];

    public function adSets(): HasMany
    {
        return $this->hasMany(AdSet::class, 'campaign_id');
    }
}
