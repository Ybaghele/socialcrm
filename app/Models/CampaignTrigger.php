<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignTrigger extends Model
{
    protected $guarded = [];
    protected $casts = ['keywords' => 'array', 'scope' => 'array', 'conditions' => 'array'];

    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
}
