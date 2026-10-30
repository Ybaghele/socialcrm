<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::in(['meta', 'google', 'linkedin'])],
            'ad_account_id' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'objective' => ['nullable', 'string', 'max:255'],
            'special_category' => ['nullable', 'string', 'max:255'],
            'budget_type' => ['nullable', Rule::in(['daily', 'lifetime'])],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['draft', 'processing', 'active', 'paused', 'archived', 'failed'])],
            'audience_json' => ['nullable', 'array'],
            'placements_json' => ['nullable', 'array'],
            'optimization_goal' => ['nullable', 'string', 'max:255'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date'],
            'format' => ['nullable', 'string', 'max:255'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'primary_text' => ['nullable', 'string'],
            'headline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'cta' => ['nullable', 'string', 'max:255'],
            'destination_url' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
