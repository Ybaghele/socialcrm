<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetaConnection extends Model
{
    /** @use HasFactory<\Database\Factories\MetaConnectionFactory> */
    use HasFactory, BelongsToWorkspace;

    protected $guarded = [];
    protected $hidden = ['access_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'meta' => 'array',
    ];
}
