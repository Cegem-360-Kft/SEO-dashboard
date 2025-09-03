<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Keyword extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'keyword',
        'keyword_hash',
        'priority',
        'categories',
        'intent',
        'country',
        'language',
        'location',
        'search_volume',
        'difficulty_score',
        'cpc',
        'competition',
        'related_keywords',
        'current_position',
        'previous_position',
        'position_last_updated',
        'is_tracking_active',
        'tags',
        'notes',
    ];

    protected $casts = [
        'categories' => 'array',
        'related_keywords' => 'array',
        'tags' => 'array',
        'difficulty_score' => 'decimal:2',
        'cpc' => 'decimal:2',
        'competition' => 'decimal:2',
        'is_tracking_active' => 'boolean',
        'position_last_updated' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($keyword) {
            // Generate hash for deduplication
            if ($keyword->keyword && !$keyword->keyword_hash) {
                $keyword->keyword_hash = md5(strtolower(trim($keyword->keyword)));
            }
        });
    }

    // Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(KeywordPosition::class);
    }

    public function serpFeatures(): HasMany
    {
        return $this->hasMany(SerpFeature::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_tracking_active', true);
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByIntent($query, string $intent)
    {
        return $query->where('intent', $intent);
    }

    public function scopeInTop10($query)
    {
        return $query->whereBetween('current_position', [1, 10]);
    }

    public function scopeInTop3($query)
    {
        return $query->whereBetween('current_position', [1, 3]);
    }

    public function scopeByCountry($query, string $country)
    {
        return $query->where('country', $country);
    }

    public function scopeWithCategories($query, array $categories)
    {
        return $query->where(function ($q) use ($categories) {
            foreach ($categories as $category) {
                $q->orWhereJsonContains('categories', $category);
            }
        });
    }

    // Analytics methods
    public function getLatestPosition(): ?KeywordPosition
    {
        return $this->positions()
            ->orderByDesc('date')
            ->first();
    }

    public function getPositionHistory(int $days = 30): \Illuminate\Database\Eloquent\Collection
    {
        return $this->positions()
            ->where('date', '>=', now()->subDays($days))
            ->orderBy('date')
            ->get();
    }

    public function getPositionChange(): int
    {
        if (!$this->current_position || !$this->previous_position) {
            return 0;
        }
        
        return $this->previous_position - $this->current_position;
    }

    public function isImproving(): bool
    {
        return $this->getPositionChange() > 0;
    }

    public function isDeclining(): bool
    {
        return $this->getPositionChange() < 0;
    }

    public function getEstimatedTraffic(): int
    {
        if (!$this->current_position || !$this->search_volume) {
            return 0;
        }

        // CTR curve based on position
        $ctrCurve = [
            1 => 0.3149, 2 => 0.1555, 3 => 0.1006, 4 => 0.0697, 5 => 0.0513,
            6 => 0.0403, 7 => 0.0329, 8 => 0.0276, 9 => 0.0238, 10 => 0.0208
        ];

        $ctr = $ctrCurve[$this->current_position] ?? 0.01;
        return intval($this->search_volume * $ctr);
    }

    public function getEstimatedValue(): float
    {
        return $this->getEstimatedTraffic() * ($this->cpc ?? 1.0);
    }

    public function getDifficultyLevel(): string
    {
        if (!$this->difficulty_score) {
            return 'unknown';
        }

        return match (true) {
            $this->difficulty_score <= 30 => 'easy',
            $this->difficulty_score <= 60 => 'medium',
            $this->difficulty_score <= 80 => 'hard',
            default => 'very_hard'
        };
    }

    public function updatePosition(int $position, string $searchEngine = 'google', string $device = 'desktop'): void
    {
        $this->previous_position = $this->current_position;
        $this->current_position = $position;
        $this->position_last_updated = now()->toDateString();
        $this->save();
    }
}
