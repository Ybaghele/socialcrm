<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Segment extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];
    protected $casts = ['filters' => 'array'];

    public function campaigns(): HasMany { return $this->hasMany(Campaign::class); }
}
