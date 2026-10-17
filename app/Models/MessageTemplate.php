<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
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
}
