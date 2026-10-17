<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyActiveContact extends Model
{
    public $timestamps = false;

    protected $guarded = [];
    protected $casts = ['period_start' => 'date', 'first_active_at' => 'datetime'];

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
