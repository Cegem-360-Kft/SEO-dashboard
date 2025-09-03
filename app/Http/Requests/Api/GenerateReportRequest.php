<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

final class GenerateReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('reports.create');
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
            'title' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:daily,weekly,monthly,quarterly,yearly,custom'],
            'start_date' => ['nullable', 'date', 'before_or_equal:end_date', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'before_or_equal:today'],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['in:overview,keyword_performance,traffic_analysis,competitor_comparison,technical_seo,recommendations'],
            'format' => ['nullable', 'in:pdf,html,both'],
            'include_charts' => ['nullable', 'boolean'],
            'include_data_tables' => ['nullable', 'boolean'],
            'email_recipients' => ['nullable', 'array'],
            'email_recipients.*' => ['email'],
            'schedule_delivery' => ['nullable', 'boolean'],
            'delivery_time' => ['nullable', 'date', 'after:now'],
            'template' => ['nullable', 'in:executive_summary,detailed_analysis,competitor_focus,keyword_focus'],
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
            'type.required' => 'Report type is required.',
            'type.in' => 'Report type must be daily, weekly, monthly, quarterly, yearly, or custom.',
            'start_date.before_or_equal' => 'Start date must be before or equal to end date and cannot be in the future.',
            'end_date.after_or_equal' => 'End date must be after or equal to start date.',
            'end_date.before_or_equal' => 'End date cannot be in the future.',
            'sections.*.in' => 'Invalid report section specified.',
            'format.in' => 'Report format must be pdf, html, or both.',
            'email_recipients.*.email' => 'All email recipients must be valid email addresses.',
            'delivery_time.after' => 'Delivery time must be in the future.',
            'template.in' => 'Invalid report template specified.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            // Validate date range is reasonable
            if ($this->start_date && $this->end_date) {
                $start = Carbon::parse($this->start_date);
                $end = Carbon::parse($this->end_date);
                $diffInDays = $start->diffInDays($end);

                // Check if date range is too long
                if ($diffInDays > 365) {
                    $validator->errors()->add('end_date', 'Date range cannot exceed 365 days.');
                }

                // Check if date range makes sense for report type
                $expectedDays = $this->getExpectedDaysForType($this->type);
                if ($expectedDays && abs($diffInDays - $expectedDays) > 7) {
                    $validator->errors()->add(
                        'type',
                        sprintf("Selected date range (%s days) doesn't match the %s report type.", $diffInDays, $this->type)
                    );
                }
            }

            // Validate scheduled delivery
            if ($this->schedule_delivery && ! $this->delivery_time) {
                $validator->errors()->add('delivery_time', 'Delivery time is required when scheduling delivery.');
            }

            // Validate email recipients for scheduled delivery
            if ($this->schedule_delivery && empty($this->email_recipients)) {
                $validator->errors()->add('email_recipients', 'Email recipients are required for scheduled delivery.');
            }

            // Validate sections array
            if ($this->sections && count($this->sections) === 0) {
                $validator->errors()->add('sections', 'At least one report section must be selected.');
            }

            // Business logic: certain combinations might not make sense
            if ($this->type === 'daily' && in_array('competitor_comparison', $this->sections ?? [])) {
                $validator->errors()->add('sections', 'Competitor comparison is not typically useful for daily reports.');
            }
        });
    }

    /**
     * Get validated data with computed values.
     */
    public function validatedWithComputed(): array
    {
        $validated = $this->validated();

        // Add computed metadata
        $validated['metadata'] = [
            'requested_by' => $this->user()->id,
            'requested_at' => now(),
            'report_settings' => [
                'include_charts' => $validated['include_charts'],
                'include_data_tables' => $validated['include_data_tables'],
                'format' => $validated['format'],
            ],
        ];

        return $validated;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default dates based on report type if not provided
        if (! $this->start_date || ! $this->end_date) {
            $dates = $this->getDefaultDateRange($this->type);

            $this->merge([
                'start_date' => $this->start_date ?? $dates['start'],
                'end_date' => $this->end_date ?? $dates['end'],
            ]);
        }

        // Set default title if not provided
        if (! $this->title) {
            $project = Project::query()->find($this->project_id);
            $projectName = $project ? $project->name : 'Unknown Project';

            $this->merge([
                'title' => $this->generateDefaultTitle($this->type, $projectName),
            ]);
        }

        // Set defaults
        $this->merge([
            'format' => $this->format ?? 'pdf',
            'include_charts' => $this->include_charts ?? true,
            'include_data_tables' => $this->include_data_tables ?? true,
            'schedule_delivery' => $this->schedule_delivery ?? false,
        ]);

        // Set default sections based on report type
        if (! $this->sections) {
            $this->merge([
                'sections' => $this->getDefaultSections($this->type),
            ]);
        }
    }

    /**
     * Get default date range based on report type.
     */
    private function getDefaultDateRange(string $type): array
    {
        $end = Carbon::now();

        $start = match ($type) {
            'daily' => $end->copy()->subDay(),
            'weekly' => $end->copy()->subWeek(),
            'monthly' => $end->copy()->subMonth(),
            'quarterly' => $end->copy()->subMonths(3),
            'yearly' => $end->copy()->subYear(),
            default => $end->copy()->subMonth(),
        };

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];
    }

    /**
     * Generate default title based on report type and project name.
     */
    private function generateDefaultTitle(string $type, string $projectName): string
    {
        $typeLabel = match ($type) {
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'yearly' => 'Annual',
            default => 'Custom',
        };

        return sprintf('%s SEO Report - %s', $typeLabel, $projectName);
    }

    /**
     * Get default sections based on report type.
     */
    private function getDefaultSections(string $type): array
    {
        return match ($type) {
            'daily' => ['overview', 'keyword_performance'],
            'weekly' => ['overview', 'keyword_performance', 'traffic_analysis'],
            'monthly' => ['overview', 'keyword_performance', 'traffic_analysis', 'competitor_comparison', 'recommendations'],
            'quarterly' => ['overview', 'keyword_performance', 'traffic_analysis', 'competitor_comparison', 'technical_seo', 'recommendations'],
            'yearly' => ['overview', 'keyword_performance', 'traffic_analysis', 'competitor_comparison', 'technical_seo', 'recommendations'],
            default => ['overview', 'keyword_performance', 'recommendations'],
        };
    }

    /**
     * Get expected number of days for report type.
     */
    private function getExpectedDaysForType(string $type): ?int
    {
        return match ($type) {
            'daily' => 1,
            'weekly' => 7,
            'monthly' => 30,
            'quarterly' => 90,
            'yearly' => 365,
            default => null,
        };
    }
}
