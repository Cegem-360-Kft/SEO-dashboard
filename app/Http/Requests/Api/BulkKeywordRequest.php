<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

final class BulkKeywordRequest extends FormRequest
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
            'keywords' => ['required', 'array', 'min:1', 'max:1000'],
            'keywords.*.term' => ['required', 'string', 'max:255', 'min:1'],
            'keywords.*.search_volume' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'keywords.*.location' => ['nullable', 'string', 'max:255'],
            'keywords.*.device' => ['nullable', 'in:desktop,mobile,tablet'],
            'keywords.*.language' => ['nullable', 'string', 'max:10'],
            'keywords.*.is_active' => ['nullable', 'boolean'],
            'keywords.*.tags' => ['nullable', 'array'],
            'keywords.*.tags.*' => ['string', 'max:50'],
            'skip_duplicates' => ['nullable', 'boolean'],
            'update_existing' => ['nullable', 'boolean'],
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
            'keywords.required' => 'Keywords array is required.',
            'keywords.min' => 'At least one keyword is required.',
            'keywords.max' => 'Cannot import more than 1000 keywords at once.',
            'keywords.*.term.required' => 'Keyword term is required for all keywords.',
            'keywords.*.term.min' => 'Each keyword must be at least 1 character long.',
            'keywords.*.term.max' => 'Each keyword cannot exceed 255 characters.',
            'keywords.*.search_volume.min' => 'Search volume cannot be negative.',
            'keywords.*.search_volume.max' => 'Search volume cannot exceed 10 million.',
            'keywords.*.device.in' => 'Device must be desktop, mobile, or tablet.',
            'keywords.*.language.max' => 'Language code cannot exceed 10 characters.',
            'keywords.*.tags.*.max' => 'Each tag cannot exceed 50 characters.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->keywords && is_array($this->keywords)) {
                $terms = [];
                $duplicatesInBatch = [];

                foreach ($this->keywords as $index => $keyword) {
                    $term = $keyword['term'] ?? '';

                    // Check for duplicates within the batch
                    if (in_array($term, $terms)) {
                        $duplicatesInBatch[] = $term;
                    } else {
                        $terms[] = $term;
                    }

                    // Validate individual keyword format
                    if ($term) {
                        // Check for invalid characters
                        if (preg_match('/[<>{}"\']/', $term)) {
                            $validator->errors()->add(
                                sprintf('keywords.%s.term', $index),
                                sprintf("Keyword '%s' contains invalid characters.", $term)
                            );
                        }

                        // Check word count
                        if (str_word_count($term) > 10) {
                            $validator->errors()->add(
                                sprintf('keywords.%s.term', $index),
                                sprintf("Keyword '%s' cannot exceed 10 words.", $term)
                            );
                        }

                        // Validate search volume reasonableness
                        $searchVolume = $keyword['search_volume'] ?? 0;
                        if ($searchVolume > 0) {
                            $termLength = str_word_count($term);
                            if ($termLength > 5 && $searchVolume > 100000) {
                                $validator->errors()->add(
                                    sprintf('keywords.%s.search_volume', $index),
                                    sprintf("Search volume for '%s' seems unusually high for a long-tail keyword.", $term)
                                );
                            }
                        }
                    }
                }

                // Report duplicates within batch
                if ($duplicatesInBatch !== []) {
                    $validator->errors()->add(
                        'keywords',
                        'Duplicate keywords found in batch: '.implode(', ', array_unique($duplicatesInBatch))
                    );
                }

                // Check if we're within reasonable limits for processing
                $keywordCount = count($this->keywords);
                if ($keywordCount > 500) {
                    $validator->errors()->add(
                        'keywords',
                        sprintf('Processing %d keywords may take a while. Consider splitting into smaller batches for better performance.', $keywordCount)
                    );
                }
            }
        });
    }

    /**
     * Get the validated data with additional processing.
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();

        // Remove empty keywords and normalize data
        if (isset($validated['keywords'])) {
            $validated['keywords'] = array_filter($validated['keywords'], function (array $keyword): bool {
                return ! empty($keyword['term']);
            });

            // Re-index array after filtering
            $validated['keywords'] = array_values($validated['keywords']);
        }

        return $validated;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Clean and normalize keyword terms
        if ($this->keywords && is_array($this->keywords)) {
            $cleanedKeywords = [];

            foreach ($this->keywords as $keyword) {
                $cleanedKeyword = $keyword;

                // Clean the term
                if (isset($keyword['term'])) {
                    $cleanTerm = mb_trim(mb_strtolower($keyword['term']));
                    // Remove extra whitespace
                    $cleanTerm = preg_replace('/\s+/', ' ', $cleanTerm);
                    $cleanedKeyword['term'] = $cleanTerm;
                }

                // Set default values
                $cleanedKeyword['is_active'] = $keyword['is_active'] ?? true;
                $cleanedKeyword['device'] = $keyword['device'] ?? 'desktop';

                $cleanedKeywords[] = $cleanedKeyword;
            }

            $this->merge([
                'keywords' => $cleanedKeywords,
                'skip_duplicates' => $this->skip_duplicates ?? true,
                'update_existing' => $this->update_existing ?? false,
            ]);
        }
    }
}
