<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Outbound\TemplateRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class MessageTemplate extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];
    protected $casts = ['components' => 'array'];

    public function channelAccount(): BelongsTo { return $this->belongsTo(ChannelAccount::class); }
    public function campaigns(): HasMany { return $this->hasMany(Campaign::class); }
    public function messages(): HasMany { return $this->hasMany(Message::class); }

    /** The default body text, with {{n}} placeholders intact. */
    public function bodyText(): string
    {
        return TemplateRenderer::bodyText($this->components ?? []);
    }

    /** How many {{1}}…{{n}} body variables Meta expects us to fill. */
    public function placeholderCount(): int
    {
        return TemplateRenderer::placeholderCount($this->components ?? []);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
