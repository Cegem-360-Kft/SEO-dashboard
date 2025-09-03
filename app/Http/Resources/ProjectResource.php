<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'description' => $this->description,
            'status' => $this->status,
            'target_location' => $this->target_location,
            'target_language' => $this->target_language,
            
            // Analytics configuration
            'gsc_property_url' => $this->gsc_property_url,
            'ga4_property_id' => $this->ga4_property_id,
            
            // Business metrics
            'avg_order_value' => $this->when($this->avg_order_value, $this->avg_order_value),
            'conversion_rate' => $this->when($this->conversion_rate, $this->conversion_rate),
            
            // Settings
            'settings' => $this->settings ?? [],
            
            // Relationships
            'keywords' => KeywordResource::collection($this->whenLoaded('keywords')),
            'competitors' => CompetitorResource::collection($this->whenLoaded('competitors')),
            'reports' => ReportResource::collection($this->whenLoaded('reports')),
            
            // Computed statistics
            'statistics' => $this->when($this->relationLoaded('keywords'), function () {
                return [
                    'total_keywords' => $this->keywords->count(),
                    'active_keywords' => $this->keywords->where('is_active', true)->count(),
                    'ranking_keywords' => $this->keywords->whereNotNull('latest_position')->count(),
                    'top_10_keywords' => $this->keywords->where('latest_position', '<=', 10)->count(),
                    'average_position' => $this->calculateAveragePosition(),
                    'total_search_volume' => $this->keywords->sum('search_volume'),
                ];
            }),
            
            // Timestamps
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Meta information
            'meta' => [
                'can_update' => $request->user()?->can('update', $this->resource),
                'can_delete' => $request->user()?->can('delete', $this->resource),
                'has_analytics' => !empty($this->ga4_property_id),
                'has_search_console' => !empty($this->gsc_property_url),
            ],
        ];
    }

    /**
     * Calculate average position for ranking keywords
     */
    private function calculateAveragePosition(): ?float
    {
        if (!$this->relationLoaded('keywords')) {
            return null;
        }

        $rankingKeywords = $this->keywords->whereNotNull('latest_position');
        
        if ($rankingKeywords->isEmpty()) {
            return null;
        }

        return round($rankingKeywords->avg('latest_position'), 2);
    }

    /**
     * Get additional attributes when showing single resource
     */
    public function with(Request $request): array
    {
        if ($request->route()?->getName() === 'api.projects.show') {
            return [
                'links' => [
                    'self' => route('api.projects.show', $this->id),
                    'keywords' => route('api.keywords.index', ['project_id' => $this->id]),
                    'reports' => route('api.reports.index', ['project_id' => $this->id]),
                    'dashboard' => route('api.projects.dashboard', $this->id),
                    'analytics' => route('api.projects.analytics', $this->id),
                ],
            ];
        }

        return [];
    }
}