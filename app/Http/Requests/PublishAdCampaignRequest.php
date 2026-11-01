<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class PublishAdCampaignRequest extends StoreAdCampaignRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Present when the wizard is editing an existing draft; omitted when
            // it is publishing a campaign for the first time.
            'campaign_id' => ['nullable', 'integer'],
        ];
    }
}
