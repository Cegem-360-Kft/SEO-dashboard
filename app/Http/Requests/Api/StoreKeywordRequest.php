<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreKeywordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('keywords.create');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'project_id' => [
                'required',
                'exists:projects,id',
                function ($attribute, $value, $fail): void {
                    $project = Project::query()->find($value);
                    if ($project && $project->tenant_id !== $this->user()->tenant_id) {
                        $fail('The selected project does not belong to your organization.');
                    }
                },
            ],
            'term' => [
                'required',
                'string',
                'max:255',
                'min:1',
                Rule::unique('keywords')->where(function ($query) {
                    return $query->where('project_id', $this->project_id);
                }),
            ],
            'search_volume' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'location' => ['nullable', 'string', 'max:255'],
            'device' => ['nullable', 'in:desktop,mobile,tablet'],
            'language' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'project_id.required' => 'Project is required.',
            'project_id.exists' => 'Selected project does not exist.',
            'term.required' => 'Keyword term is required.',
            'term.unique' => 'This keyword already exists for the selected project.',
            'term.min' => 'Keyword must be at least 1 character long.',
            'term.max' => 'Keyword cannot exceed 255 characters.',
            'search_volume.min' => 'Search volume cannot be negative.',
            'search_volume.max' => 'Search volume cannot exceed 10 million.',
            'device.in' => 'Device must be desktop, mobile, or tablet.',
            'language.max' => 'Language code cannot exceed 10 characters.',
            'tags.*.max' => 'Each tag cannot exceed 50 characters.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            // Additional validation for keyword term format
            if ($this->term) {
                // Check for invalid characters
                if (preg_match('/[<>{}"\']/', $this->term)) {
                    $validator->errors()->add('term', 'Keyword contains invalid characters.');
                }

                // Check minimum word count (optional business rule)
                if (str_word_count($this->term) > 10) {
                    $validator->errors()->add('term', 'Keyword cannot exceed 10 words.');
                }
            }

            // Validate search volume is reasonable
            if ($this->search_volume && $this->search_volume > 0) {
                $term_length = str_word_count($this->term ?? '');
                // Very long tail keywords shouldn't have extremely high volume
                if ($term_length > 5 && $this->search_volume > 100000) {
                    $validator->errors()->add('search_volume', 'Search volume seems unusually high for a long-tail keyword.');
                }
            }
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Clean and normalize the keyword term
        if ($this->term) {
            $cleanTerm = mb_trim(mb_strtolower($this->term));
            // Remove extra whitespace
            $cleanTerm = preg_replace('/\s+/', ' ', $cleanTerm);

            $this->merge([
                'term' => $cleanTerm,
            ]);
        }

        // Set default values
        $this->merge([
            'is_active' => $this->is_active ?? true,
            'device' => $this->device ?? 'desktop',
        ]);
    }
}
