<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CannedResponse extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
