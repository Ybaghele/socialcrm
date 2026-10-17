<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany, HasManyThrough};
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use BelongsToWorkspace, SoftDeletes;

    protected $guarded = [];
    protected $casts = [
        'source_details' => 'array',
        'custom_fields' => 'array',
        'is_opted_in' => 'boolean',
        'opted_out_at' => 'datetime',
        'last_interaction_at' => 'datetime',
    ];

    public function stage(): BelongsTo { return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function identities(): HasMany { return $this->hasMany(ContactChannelIdentity::class); }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
    public function tags(): BelongsToMany { return $this->belongsToMany(Tag::class)->withPivot('applied_by')->withTimestamps(); }
    public function enrollments(): HasMany { return $this->hasMany(CampaignEnrollment::class); }
    public function campaigns(): BelongsToMany { return $this->belongsToMany(Campaign::class, 'campaign_enrollments')->withPivot(['status', 'won_at'])->withTimestamps(); }
    public function activityLogs(): HasMany { return $this->hasMany(ActivityLog::class)->latest('created_at'); }

    // "Total DMs" column in the CRM table.
    public function messages(): HasManyThrough { return $this->hasManyThrough(Message::class, Conversation::class); }
}
