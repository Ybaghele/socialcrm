<?php

namespace App\Models;

use App\Enums\WorkspaceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany, HasOne};
use Illuminate\Database\Eloquent\SoftDeletes;

class Workspace extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'limits_override' => 'array',
        'status' => WorkspaceStatus::class,
        'suspended_at' => 'datetime',
        'onboarded_at' => 'datetime',
    ];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['role', 'job_title', 'is_available', 'last_assigned_at'])
            ->withTimestamps();
    }

    public function invitations(): HasMany { return $this->hasMany(WorkspaceInvitation::class); }
    public function subscription(): HasOne { return $this->hasOne(Subscription::class)->latestOfMany(); }
    public function wallet(): HasOne { return $this->hasOne(Wallet::class); }
    public function channelAccounts(): HasMany { return $this->hasMany(ChannelAccount::class); }
    public function contacts(): HasMany { return $this->hasMany(Contact::class); }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
    public function campaigns(): HasMany { return $this->hasMany(Campaign::class); }
    public function pipelineStages(): HasMany { return $this->hasMany(PipelineStage::class)->orderBy('position'); }
    public function tags(): HasMany { return $this->hasMany(Tag::class); }
    public function segments(): HasMany { return $this->hasMany(Segment::class); }
    public function messageTemplates(): HasMany { return $this->hasMany(MessageTemplate::class); }
    public function cannedResponses(): HasMany { return $this->hasMany(CannedResponse::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function monthlyActiveContacts(): HasMany { return $this->hasMany(MonthlyActiveContact::class); }

    public function isSuspended(): bool
    {
        return $this->status === WorkspaceStatus::Suspended;
    }

    /** Can this tenant use the product right now? (not suspended + paid or in a live trial) */
    public function hasAccess(): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        $subscription = $this->subscription;

        if (! $subscription) {
            return false;
        }

        return match ($subscription->status) {
            'active', 'past_due' => true, // past_due = grace period while Razorpay retries
            'trialing' => $subscription->trial_ends_at?->isFuture() ?? false,
            default => false,
        };
    }
}
