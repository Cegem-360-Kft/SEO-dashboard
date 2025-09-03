<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('projects.update');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $projectId = $this->route('project')->id;

        return [
            'name' => 'sometimes|string|max:255',
            'domain' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^https?:\/\/.+/',
                Rule::unique('projects')->where(function ($query) {
                    return $query->where('tenant_id', $this->user()->tenant_id);
                })->ignore($projectId),
            ],
            'description' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:active,inactive,archived',
            'target_location' => 'nullable|string|max:255',
            'target_language' => 'nullable|string|max:10',
            'gsc_property_url' => 'nullable|url|max:255',
            'ga4_property_id' => 'nullable|string|max:50',
            'avg_order_value' => 'nullable|numeric|min:0',
            'conversion_rate' => 'nullable|numeric|min:0|max:1',
            'settings' => 'nullable|array',
            'settings.tracking_frequency' => 'nullable|in:daily,weekly,monthly',
            'settings.notification_preferences' => 'nullable|array',
            'settings.api_integrations' => 'nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Domain must be a valid URL starting with http:// or https://',
            'domain.unique' => 'This domain is already being tracked in your account.',
            'gsc_property_url.url' => 'Google Search Console property URL must be a valid URL.',
            'ga4_property_id.max' => 'Google Analytics 4 property ID cannot exceed 50 characters.',
            'conversion_rate.max' => 'Conversion rate cannot exceed 100% (1.0).',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure domain has protocol if provided
        if ($this->domain && !str_starts_with($this->domain, 'http')) {
            $this->merge([
                'domain' => 'https://' . $this->domain
            ]);
        }

        // Normalize domain (remove trailing slash)
        if ($this->domain) {
            $this->merge([
                'domain' => rtrim($this->domain, '/')
            ]);
        }
    }
}