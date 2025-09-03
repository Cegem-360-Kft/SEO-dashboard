<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkKeywordRequest;
use App\Http\Requests\Api\StoreKeywordRequest;
use App\Http\Resources\KeywordResource;
use App\Models\Keyword;
use App\Models\KeywordPosition;
use App\Models\Project;
use App\Services\SEOCalculationService;
use App\Services\SerpTrackingService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class KeywordController extends Controller
{
    public function __construct(
        private SerpTrackingService $serpTrackingService,
        private SEOCalculationService $seoCalculationService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('api.tenant');
    }

    /**
     * Display a listing of keywords
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->tenant->keywords()
            ->with(['project', 'positions' => function ($q): void {
                $q->orderBy('tracked_at', 'desc')->limit(5);
            }]);

        // Filter by project
        if ($request->project_id) {
            $query->where('project_id', $request->project_id);
        }

        // Search filter
        if ($request->search) {
            $query->where('term', 'ILIKE', sprintf('%%%s%%', $request->search));
        }

        // Status filter
        if ($request->status) {
            $isActive = $request->status === 'active';
            $query->where('is_active', $isActive);
        }

        // Position range filter
        if ($request->position_from || $request->position_to) {
            $query->where(function ($q) use ($request): void {
                if ($request->position_from) {
                    $q->where('latest_position', '>=', $request->position_from);
                }

                if ($request->position_to) {
                    $q->where('latest_position', '<=', $request->position_to);
                }
            });
        }

        // Search volume range filter
        if ($request->volume_from || $request->volume_to) {
            $query->where(function ($q) use ($request): void {
                if ($request->volume_from) {
                    $q->where('search_volume', '>=', $request->volume_from);
                }

                if ($request->volume_to) {
                    $q->where('search_volume', '<=', $request->volume_to);
                }
            });
        }

        // Sort options
        $sortField = $request->sort ?? 'created_at';
        $sortDirection = $request->direction ?? 'desc';

        if ($sortField === 'position') {
            $query->orderByRaw('latest_position IS NULL, latest_position '.$sortDirection);
        } else {
            $query->orderBy($sortField, $sortDirection);
        }

        $keywords = $query->paginate($request->per_page ?? 50);

        return KeywordResource::collection($keywords);
    }

    /**
     * Store a newly created keyword
     */
    public function store(StoreKeywordRequest $request): KeywordResource
    {
        $project = Project::query()->findOrFail($request->project_id);
        $this->authorize('update', $project);

        // Calculate initial difficulty score
        $difficulty = $this->seoCalculationService->calculateKeywordDifficulty(
            $request->term,
            $project->domain
        );

        $keyword = $project->keywords()->create([
            'term' => $request->term,
            'search_volume' => $request->search_volume,
            'difficulty' => $difficulty,
            'location' => $request->location ?? $project->target_location,
            'device' => $request->device ?? 'desktop',
            'language' => $request->language ?? $project->target_language,
            'is_active' => $request->is_active ?? true,
            'tags' => $request->tags ?? [],
        ]);

        $keyword->load(['project', 'positions']);

        return new KeywordResource($keyword);
    }

    /**
     * Display the specified keyword
     */
    public function show(Keyword $keyword): KeywordResource
    {
        $this->authorize('view', $keyword->project);

        $keyword->load([
            'project',
            'positions' => function ($query): void {
                $query->with('serpFeatures')
                    ->orderBy('tracked_at', 'desc')
                    ->limit(100);
            },
        ]);

        return new KeywordResource($keyword);
    }

    /**
     * Update the specified keyword
     */
    public function update(Request $request, Keyword $keyword): KeywordResource
    {
        $this->authorize('update', $keyword->project);

        $request->validate([
            'search_volume' => ['nullable', 'integer', 'min:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'device' => ['nullable', 'in:desktop,mobile,tablet'],
            'language' => ['nullable', 'string', 'max:10'],
            'is_active' => ['boolean'],
            'tags' => ['nullable', 'array'],
        ]);

        $keyword->update($request->only([
            'search_volume', 'location', 'device', 'language', 'is_active', 'tags',
        ]));

        $keyword->load(['project', 'positions']);

        return new KeywordResource($keyword);
    }

    /**
     * Remove the specified keyword
     */
    public function destroy(Keyword $keyword): JsonResponse
    {
        $this->authorize('update', $keyword->project);

        $keyword->delete();

        return response()->json(['message' => 'Keyword deleted successfully']);
    }

    /**
     * Bulk import keywords
     */
    public function bulkImport(BulkKeywordRequest $request): JsonResponse
    {
        $project = Project::query()->findOrFail($request->project_id);
        $this->authorize('update', $project);

        $keywords = $request->keywords;
        $imported = 0;
        $duplicates = 0;
        $errors = [];

        DB::transaction(function () use ($keywords, $project, &$imported, &$duplicates, &$errors): void {
            foreach ($keywords as $keywordData) {
                try {
                    // Check for duplicates
                    $exists = $project->keywords()
                        ->where('term', $keywordData['term'])
                        ->exists();

                    if ($exists) {
                        $duplicates++;

                        continue;
                    }

                    // Calculate difficulty
                    $difficulty = $this->seoCalculationService->calculateKeywordDifficulty(
                        $keywordData['term'],
                        $project->domain
                    );

                    $project->keywords()->create([
                        'term' => $keywordData['term'],
                        'search_volume' => $keywordData['search_volume'] ?? null,
                        'difficulty' => $difficulty,
                        'location' => $keywordData['location'] ?? $project->target_location,
                        'device' => $keywordData['device'] ?? 'desktop',
                        'language' => $keywordData['language'] ?? $project->target_language,
                        'is_active' => $keywordData['is_active'] ?? true,
                        'tags' => $keywordData['tags'] ?? [],
                    ]);

                    $imported++;
                } catch (Exception $e) {
                    $errors[] = [
                        'keyword' => $keywordData['term'],
                        'error' => $e->getMessage(),
                    ];
                }
            }
        });

        return response()->json([
            'message' => 'Bulk import completed',
            'imported' => $imported,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'total_processed' => count($keywords),
        ]);
    }

    /**
     * Bulk update keywords
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'keyword_ids' => ['required', 'array'],
            'keyword_ids.*' => ['exists:keywords,id'],
            'updates' => ['required', 'array'],
        ]);

        $keywords = $request->user()->tenant->keywords()
            ->whereIn('id', $request->keyword_ids)
            ->get();

        $updated = 0;
        foreach ($keywords as $keyword) {
            if ($this->authorize('update', $keyword->project, false)) {
                $keyword->update($request->updates);
                $updated++;
            }
        }

        return response()->json([
            'message' => sprintf('Updated %d keywords successfully', $updated),
            'updated_count' => $updated,
        ]);
    }

    /**
     * Track keyword positions manually
     */
    public function track(Request $request, Keyword $keyword): JsonResponse
    {
        $this->authorize('update', $keyword->project);

        $position = $this->serpTrackingService->trackKeywordPosition($keyword);

        if ($position instanceof KeywordPosition) {
            return response()->json([
                'message' => 'Position tracked successfully',
                'position' => [
                    'position' => $position->position,
                    'url' => $position->url,
                    'tracked_at' => $position->tracked_at,
                ],
            ]);
        }

        return response()->json([
            'message' => 'Failed to track position',
            'error' => 'Unable to fetch SERP data',
        ], 422);
    }

    /**
     * Bulk track multiple keywords
     */
    public function bulkTrack(Request $request): JsonResponse
    {
        $request->validate([
            'keyword_ids' => ['required', 'array', 'max:100'],
            'keyword_ids.*' => ['exists:keywords,id'],
        ]);

        $keywords = $request->user()->tenant->keywords()
            ->whereIn('id', $request->keyword_ids)
            ->with('project')
            ->get();

        $tracked = 0;
        $errors = [];

        foreach ($keywords as $keyword) {
            if ($this->authorize('update', $keyword->project, false)) {
                try {
                    $position = $this->serpTrackingService->trackKeywordPosition($keyword);
                    if ($position instanceof KeywordPosition) {
                        $tracked++;
                    }
                } catch (Exception $e) {
                    $errors[] = [
                        'keyword' => $keyword->term,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return response()->json([
            'message' => 'Bulk tracking completed',
            'tracked' => $tracked,
            'errors' => $errors,
            'total_processed' => $keywords->count(),
        ]);
    }

    /**
     * Get keyword position history
     */
    public function positionHistory(Request $request, Keyword $keyword): JsonResponse
    {
        $this->authorize('view', $keyword->project);

        $days = $request->days ?? 30;
        $positions = $this->serpTrackingService->getPositionHistory($keyword, $days);

        $history = $positions->map(function ($position): array {
            return [
                'position' => $position->position,
                'url' => $position->url,
                'tracked_at' => $position->tracked_at,
                'serp_features' => $position->serp_features ?? [],
            ];
        });

        $trends = $this->serpTrackingService->calculatePositionTrends($keyword);

        return response()->json([
            'keyword' => $keyword->term,
            'history' => $history,
            'trends' => $trends,
            'period_days' => $days,
        ]);
    }

    /**
     * Get keyword suggestions based on current keywords
     */
    public function suggestions(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $request->validate([
            'seed_keyword' => ['required', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // This would integrate with keyword research tools
        // For now, return a placeholder structure
        $suggestions = [
            'seed_keyword' => $request->seed_keyword,
            'suggestions' => [],
            'related_questions' => [],
            'search_volume_data' => [],
        ];

        return response()->json($suggestions);
    }

    /**
     * Get competitors ranking for a keyword
     */
    public function competitors(Keyword $keyword): JsonResponse
    {
        $this->authorize('view', $keyword->project);

        $competitors = $this->serpTrackingService->getCompetitorsInSerp($keyword);

        return response()->json([
            'keyword' => $keyword->term,
            'competitors' => $competitors,
            'last_updated' => $keyword->positions()->latest()->value('tracked_at'),
        ]);
    }

    /**
     * Get keyword performance summary
     */
    public function performance(Request $request): JsonResponse
    {
        $keywords = $request->user()->tenant->keywords()
            ->where('is_active', true);

        if ($request->project_id) {
            $keywords->where('project_id', $request->project_id);
        }

        $keywords = $keywords->with('positions')->get();

        // Calculate performance metrics
        $performance = [
            'total_keywords' => $keywords->count(),
            'ranking_keywords' => $keywords->whereNotNull('latest_position')->count(),
            'top_3_keywords' => $keywords->where('latest_position', '<=', 3)->count(),
            'top_10_keywords' => $keywords->where('latest_position', '<=', 10)->count(),
            'top_50_keywords' => $keywords->where('latest_position', '<=', 50)->count(),
            'average_position' => 0,
            'total_search_volume' => $keywords->sum('search_volume'),
            'visibility_score' => 0,
            'trends' => $this->seoCalculationService->calculateKeywordTrends($keywords),
            'volume_distribution' => $this->seoCalculationService->calculateSearchVolumeTrends($keywords),
        ];

        // Calculate averages
        $rankingKeywords = $keywords->whereNotNull('latest_position');
        if ($rankingKeywords->count() > 0) {
            $performance['average_position'] = round($rankingKeywords->avg('latest_position'), 2);
        }

        // Calculate visibility score
        if ($keywords->isNotEmpty()) {
            $totalVolume = $keywords->sum('search_volume');
            $weightedPositions = 0;

            foreach ($keywords as $keyword) {
                if ($keyword->latest_position && $keyword->search_volume) {
                    $ctr = $this->getCtrlForPosition($keyword->latest_position);
                    $weightedPositions += ($keyword->search_volume * $ctr);
                }
            }

            $performance['visibility_score'] = $totalVolume > 0
                ? round(($weightedPositions / $totalVolume) * 100, 2)
                : 0;
        }

        return response()->json($performance);
    }

    /**
     * Export keywords to CSV
     */
    public function export(Request $request): JsonResponse
    {
        $keywords = $request->user()->tenant->keywords();

        if ($request->project_id) {
            $keywords->where('project_id', $request->project_id);
        }

        if ($request->keyword_ids) {
            $keywords->whereIn('id', $request->keyword_ids);
        }

        $keywords = $keywords->with(['project', 'positions' => function ($query): void {
            $query->orderBy('tracked_at', 'desc')->limit(1);
        }])->get();

        $csvData = [];
        $csvData[] = [
            'Keyword', 'Project', 'Current Position', 'Search Volume',
            'Difficulty', 'Location', 'Device', 'Status', 'Last Tracked',
        ];

        foreach ($keywords as $keyword) {
            $latestPosition = $keyword->positions->first();

            $csvData[] = [
                $keyword->term,
                $keyword->project->name,
                $keyword->latest_position ?? 'Not Ranking',
                $keyword->search_volume ?? 'N/A',
                $keyword->difficulty ?? 'N/A',
                $keyword->location,
                $keyword->device,
                $keyword->is_active ? 'Active' : 'Inactive',
                $latestPosition?->tracked_at?->format('Y-m-d H:i:s') ?? 'Never',
            ];
        }

        $filename = 'keywords_export_'.now()->format('Y-m-d_H-i-s').'.csv';
        $filePath = storage_path('app/exports/'.$filename);

        // Ensure directory exists
        if (! file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        $file = fopen($filePath, 'w');
        foreach ($csvData as $row) {
            fputcsv($file, $row);
        }

        fclose($file);

        return response()->json([
            'message' => 'Export completed',
            'filename' => $filename,
            'download_url' => route('api.keywords.download-export', $filename),
            'total_keywords' => count($csvData) - 1, // Subtract header row
        ]);
    }

    /**
     * Download export file
     */
    public function downloadExport(string $filename): BinaryFileResponse
    {
        $filePath = storage_path('app/exports/'.$filename);

        if (! file_exists($filePath)) {
            abort(404, 'Export file not found');
        }

        return response()->download($filePath)->deleteFileAfterSend();
    }

    /**
     * Get CTR estimate for position
     */
    private function getCtrlForPosition(int $position): float
    {
        return match (true) {
            $position === 1 => 31.7,
            $position === 2 => 24.7,
            $position === 3 => 18.7,
            $position <= 10 => max(2.5, 32 - ($position * 3)),
            $position <= 20 => 1.5,
            $position <= 50 => 0.5,
            default => 0.1,
        };
    }
}
