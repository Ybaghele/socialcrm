<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCreative extends Model
{
    /** @use HasFactory<\Database\Factories\AdCreativeFactory> */
    use HasFactory;

    protected $guarded = [];

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(AdSet::class, 'ad_set_id');
    }
}
