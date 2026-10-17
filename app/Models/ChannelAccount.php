<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelAccount extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];
    protected $hidden = ['access_token', 'webhook_verify_token'];
    protected $casts = [
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'last_webhook_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * Meta tokens are copied out of a dashboard by hand, so they routinely arrive
     * with a trailing newline, surrounding quotes or an accidental "Bearer "
     * prefix — any of which makes Graph reply "Cannot parse access token".
     */
    public static function normaliseToken(?string $token): ?string
    {
        if (! is_string($token)) {
            return $token;
        }

        $token = preg_replace('/[\s"\']+/', '', trim($token));
        $token = preg_replace('/^bearer/i', '', $token);

        return $token === '' ? null : $token;
    }

    /** Meta node ids are digits; strip whatever a copy-paste may have carried. */
    public static function normaliseId(?string $value): ?string
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = preg_replace('/\s+/', '', $value);

        return $value === '' ? null : $value;
    }

    public function identities(): HasMany { return $this->hasMany(ContactChannelIdentity::class); }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
    public function campaigns(): HasMany { return $this->hasMany(Campaign::class); }
    public function messageTemplates(): HasMany { return $this->hasMany(MessageTemplate::class); }
}
