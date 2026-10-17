<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];
    protected $casts = ['is_default' => 'boolean', 'is_won' => 'boolean'];

    public function contacts(): HasMany { return $this->hasMany(Contact::class); }
}
