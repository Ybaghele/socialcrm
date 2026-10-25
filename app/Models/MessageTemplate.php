<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Outbound\TemplateRenderer;
use App\Support\HeaderImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageTemplate extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected $casts = ['components' => 'array', 'is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** The default body text, with {{n}} placeholders intact. */
    public function bodyText(): string
    {
        return TemplateRenderer::bodyText($this->components ?? []);
    }

    public function headerText(): ?string
    {
        return TemplateRenderer::headerText($this->components ?? []);
    }

    /** True when Meta will require an image parameter every time this is sent. */
    public function requiresImageHeader(): bool
    {
        return TemplateRenderer::hasImageHeader($this->components ?? []);
    }

    /** The sample image uploaded at creation, when this template was built here. */
    public function headerImageUrl(): ?string
    {
        return HeaderImage::url($this->header_image_path);
    }

    public function footerText(): ?string
    {
        return TemplateRenderer::footerText($this->components ?? []);
    }

    /** @return array<int, array{type: string, text: string, url?: string, phone_number?: string}> */
    public function buttons(): array
    {
        return TemplateRenderer::buttons($this->components ?? []);
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

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
