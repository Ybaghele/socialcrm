<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignStep extends Model
{
    protected $guarded = [];
    protected $casts = ['config' => 'array'];

    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function template(): BelongsTo { return $this->belongsTo(MessageTemplate::class, 'message_template_id'); }
}
