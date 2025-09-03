<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProjectRequest;
use App\Http\Requests\Api\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\AnalyticsService;
use App\Services\SEOCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    public function __construct(
        private AnalyticsService $analyticsService,
        private SEOCalculationService $seoCalculationService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('api.tenant');
    }

    /**
     * Display a listing of projects
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $projects = $request->user()->tenant->projects()
            ->with(['keywords', 'competitors'])
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'ILIKE', "%{$search}%")
                      ->orWhere('domain', 'ILIKE', "%{$search}%");
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->orderBy($request->sort ?? 'created_at', $request->direction ?? 'desc')
            ->paginate($request->per_page ?? 15);

        return ProjectResource::collection($projects);
    }

    /**
     * Store a newly created project
     */
    public function store(StoreProjectRequest $request): ProjectResource
    {
        $project = $request->user()->tenant->projects()->create([
            'name' => $request->name,
            'domain' => $request->domain,
            'description' => $request->description,
            'status' => $request->status ?? 'active',
            'target_location' => $request->target_location ?? 'United States',
            'target_language' => $request->target_language ?? 'en',
            'gsc_property_url' => $request->gsc_property_url,
            'ga4_property_id' => $request->ga4_property_id,
            'avg_order_value' => $request->avg_order_value,
            'conversion_rate' => $request->conversion_rate,
            'settings' => $request->settings ?? [],
        ]);

        $project->load(['keywords', 'competitors']);

        return new ProjectResource($project);
    }

    /**
     * Display the specified project
     */
    public function show(Project $project): ProjectResource
    {
        $this->authorize('view', $project);
        
        $project->load([
            'keywords' => function($query) {
                $query->with(['positions' => function($q) {
                    $q->orderBy('tracked_at', 'desc')->limit(30);
                }]);
            },
            'competitors.keywordPositions',
            'reports' => function($query) {
                $query->orderBy('created_at', 'desc')->limit(5);
            }
        ]);

        return new ProjectResource($project);
    }

    /**
     * Update the specified project
     */
    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $this->authorize('update', $project);

        $project->update($request->validated());
        $project->load(['keywords', 'competitors']);

        return new ProjectResource($project);
    }

    /**
     * Remove the specified project
     */
    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return response()->json(['message' => 'Project deleted successfully']);
    }

    /**
     * Get project dashboard data
     */
    public function dashboard(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $metrics = $this->analyticsService->calculateSeoMetrics($project);
        $visibility = $this->seoCalculationService->calculateVisibilityScore($project);
        $trafficPotential = $this->seoCalculationService->calculateTrafficPotential($project);
        
        $recentPositions = $project->keywords()
            ->with(['positions' => function($query) {
                $query->where('tracked_at', '>=', now()->subDays(7))
                      ->orderBy('tracked_at', 'desc');
            }])
            ->where('is_active', true)
            ->get();

        $dashboardData = [
            'project' => new ProjectResource($project),
            'metrics' => array_merge($metrics, [
                'visibility_score' => $visibility,
                'traffic_potential' => $trafficPotential,
            ]),
            'recent_activity' => [
                'position_changes' => $this->getRecentPositionChanges($recentPositions),
                'new_rankings' => $this->getNewRankings($project),
                'lost_rankings' => $this->getLostRankings($project),
            ],
            'charts' => [
                'position_trends' => $this->getPositionTrendsChart($project),
                'visibility_history' => $this->getVisibilityHistory($project),
            ],
        ];

        return response()->json($dashboardData);
    }

    /**
     * Get project analytics data
     */
    public function analytics(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $startDate = $request->start_date ?? now()->subDays(30)->format('Y-m-d');
        $endDate = $request->end_date ?? now()->format('Y-m-d');

        $analytics = [];
        
        // Get Search Console data if configured
        if ($project->gsc_property_url) {
            $analytics['search_console'] = $this->analyticsService->getSearchConsoleData($project, [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        }
        
        // Get Analytics data if configured
        if ($project->ga4_property_id) {
            $analytics['google_analytics'] = $this->analyticsService->getAnalyticsData($project, [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        }
        
        // Get organic keyword traffic
        $analytics['keyword_traffic'] = $this->analyticsService->getOrganicTrafficForKeywords($project);

        return response()->json($analytics);
    }

    /**
     * Bulk update project settings
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'project_ids' => 'required|array',
            'project_ids.*' => 'exists:projects,id',
            'updates' => 'required|array',
        ]);

        $projects = $request->user()->tenant->projects()
            ->whereIn('id', $request->project_ids)
            ->get();

        $updated = 0;
        foreach ($projects as $project) {
            if ($this->authorize('update', $project, false)) {
                $project->update($request->updates);
                $updated++;
            }
        }

        return response()->json([
            'message' => "Updated {$updated} projects successfully",
            'updated_count' => $updated,
        ]);
    }

    /**
     * Archive/restore project
     */
    public function archive(Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $isArchived = $project->status === 'archived';
        $project->update([
            'status' => $isArchived ? 'active' : 'archived'
        ]);

        $action = $isArchived ? 'restored' : 'archived';
        return response()->json(['message' => "Project {$action} successfully"]);
    }

    /**
     * Get competitors for a project
     */
    public function competitors(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $competitors = $project->competitors()
            ->with(['keywordPositions' => function($query) {
                $query->orderBy('tracked_at', 'desc')->limit(10);
            }])
            ->get();

        $competitorData = $competitors->map(function($competitor) {
            $averagePosition = $competitor->keywordPositions->avg('position');
            $totalKeywords = $competitor->keywordPositions->count();
            
            return [
                'id' => $competitor->id,
                'name' => $competitor->name,
                'domain' => $competitor->domain,
                'average_position' => round($averagePosition ?? 0, 2),
                'total_keywords' => $totalKeywords,
                'last_updated' => $competitor->keywordPositions->first()?->tracked_at,
                'trend' => $this->calculateCompetitorTrend($competitor),
            ];
        });

        return response()->json($competitorData);
    }

    /**
     * Get recent position changes
     */
    private function getRecentPositionChanges($keywords): array
    {
        $changes = [];
        
        foreach ($keywords as $keyword) {
            if ($keyword->positions->count() >= 2) {
                $latest = $keyword->positions->first();
                $previous = $keyword->positions->skip(1)->first();
                
                if ($latest->position && $previous->position) {
                    $change = $previous->position - $latest->position;
                    
                    if (abs($change) >= 3) {
                        $changes[] = [
                            'keyword' => $keyword->term,
                            'old_position' => $previous->position,
                            'new_position' => $latest->position,
                            'change' => $change,
                            'direction' => $change > 0 ? 'up' : 'down',
                            'tracked_at' => $latest->tracked_at,
                        ];
                    }
                }
            }
        }

        return array_slice($changes, 0, 10);
    }

    /**
     * Get new rankings (keywords that entered top 100)
     */
    private function getNewRankings(Project $project): array
    {
        return $project->keywords()
            ->whereHas('positions', function($query) {
                $query->where('tracked_at', '>=', now()->subDays(7))
                      ->whereNotNull('position')
                      ->where('position', '<=', 100);
            })
            ->whereDoesntHave('positions', function($query) {
                $query->where('tracked_at', '<', now()->subDays(7))
                      ->whereNotNull('position');
            })
            ->with(['positions' => function($query) {
                $query->orderBy('tracked_at', 'desc')->limit(1);
            }])
            ->limit(10)
            ->get()
            ->map(function($keyword) {
                return [
                    'keyword' => $keyword->term,
                    'position' => $keyword->positions->first()->position,
                    'tracked_at' => $keyword->positions->first()->tracked_at,
                ];
            })
            ->toArray();
    }

    /**
     * Get lost rankings (keywords that dropped out of top 100)
     */
    private function getLostRankings(Project $project): array
    {
        return $project->keywords()
            ->whereHas('positions', function($query) {
                $query->where('tracked_at', '<', now()->subDays(7))
                      ->whereNotNull('position')
                      ->where('position', '<=', 100);
            })
            ->whereDoesntHave('positions', function($query) {
                $query->where('tracked_at', '>=', now()->subDays(7))
                      ->whereNotNull('position')
                      ->where('position', '<=', 100);
            })
            ->limit(10)
            ->get()
            ->map(function($keyword) {
                $lastPosition = $keyword->positions()
                    ->where('tracked_at', '<', now()->subDays(7))
                    ->whereNotNull('position')
                    ->orderBy('tracked_at', 'desc')
                    ->first();
                
                return [
                    'keyword' => $keyword->term,
                    'last_position' => $lastPosition->position ?? null,
                    'lost_at' => $lastPosition->tracked_at ?? null,
                ];
            })
            ->toArray();
    }

    /**
     * Get position trends chart data
     */
    private function getPositionTrendsChart(Project $project): array
    {
        $positions = $project->keywords()
            ->with(['positions' => function($query) {
                $query->where('tracked_at', '>=', now()->subDays(30))
                      ->orderBy('tracked_at', 'asc');
            }])
            ->where('is_active', true)
            ->get();

        $chartData = [];
        $dates = [];

        foreach ($positions as $keyword) {
            foreach ($keyword->positions as $position) {
                $date = $position->tracked_at->format('Y-m-d');
                $dates[] = $date;
                
                if (!isset($chartData[$date])) {
                    $chartData[$date] = ['date' => $date, 'positions' => []];
                }
                
                $chartData[$date]['positions'][] = $position->position;
            }
        }

        // Calculate average position per day
        $result = [];
        foreach ($chartData as $data) {
            $avgPosition = count($data['positions']) > 0 
                ? array_sum($data['positions']) / count($data['positions']) 
                : 0;
            
            $result[] = [
                'date' => $data['date'],
                'average_position' => round($avgPosition, 2),
                'keyword_count' => count($data['positions']),
            ];
        }

        return $result;
    }

    /**
     * Get visibility history
     */
    private function getVisibilityHistory(Project $project): array
    {
        // This would be more complex in a real implementation
        // tracking visibility score over time
        return [];
    }

    /**
     * Calculate competitor trend
     */
    private function calculateCompetitorTrend($competitor): string
    {
        $recentPositions = $competitor->keywordPositions()
            ->where('tracked_at', '>=', now()->subWeeks(2))
            ->orderBy('tracked_at', 'desc')
            ->get();

        if ($recentPositions->count() < 2) {
            return 'stable';
        }

        $recent = $recentPositions->take($recentPositions->count() / 2);
        $older = $recentPositions->skip($recentPositions->count() / 2);

        $recentAvg = $recent->avg('position');
        $olderAvg = $older->avg('position');

        if ($recentAvg < $olderAvg - 2) {
            return 'improving';
        } elseif ($recentAvg > $olderAvg + 2) {
            return 'declining';
        }

        return 'stable';
    }
}