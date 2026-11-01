<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per attempt to publish a campaign to Meta.
 *
 * The raw Graph response is kept verbatim so a support engineer can see exactly
 * what Meta said, while `error_user_title` / `error_user_message` hold the plain
 * English version the wizard renders.
 */
class PublishLog extends Model
{
    use BelongsToWorkspace;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }
}
