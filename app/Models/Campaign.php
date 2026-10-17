<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany};
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use BelongsToWorkspace, SoftDeletes;

    protected $guarded = [];
    protected $casts = [
        'audience_description' => 'array',
        'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime',
    ];

    public function channelAccount(): BelongsTo { return $this->belongsTo(ChannelAccount::class); }
    public function segment(): BelongsTo { return $this->belongsTo(Segment::class); }
    public function template(): BelongsTo { return $this->belongsTo(MessageTemplate::class, 'message_template_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function triggers(): HasMany { return $this->hasMany(CampaignTrigger::class); }
    public function steps(): HasMany { return $this->hasMany(CampaignStep::class)->orderBy('position'); }
    public function enrollments(): HasMany { return $this->hasMany(CampaignEnrollment::class); }
    public function contacts(): BelongsToMany { return $this->belongsToMany(Contact::class, 'campaign_enrollments')->withPivot(['status', 'delivered_at', 'replied_at', 'won_at'])->withTimestamps(); }
    public function messages(): HasMany { return $this->hasMany(Message::class); }

    // Reply rate shown on the campaign card.
    public function getReplyRateAttribute(): string
    {
        return $this->delivered_count ? number_format($this->replied_count / $this->delivered_count * 100, 1).'%' : '0%';
    }
}
