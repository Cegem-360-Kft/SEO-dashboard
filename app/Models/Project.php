<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Project extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'url',
        'domain',
        'description',
        'target_countries',
        'target_languages',
        'search_engines',
        'devices',
        'integrations',
        'settings',
        'is_active',
        'last_crawled_at',
        'last_positions_updated_at',
    ];

    protected $casts = [
        'target_countries' => 'array',
        'target_languages' => 'array',
        'search_engines' => 'array',
        'devices' => 'array',
        'integrations' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'last_crawled_at' => 'datetime',
        'last_positions_updated_at' => 'datetime',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    // Analytics methods
    public function getTotalKeywords(): int
    {
        return $this->keywords()->count();
    }

    public function getActiveKeywords(): int
    {
        return $this->keywords()->where('is_tracking_active', true)->count();
    }

    public function getTop10Keywords(): int
    {
        return $this->keywords()->whereBetween('current_position', [1, 10])->count();
    }

    public function getAveragePosition(): float
    {
        return $this->keywords()
            ->whereNotNull('current_position')
            ->avg('current_position') ?? 0.0;
    }

    public function needsPositionUpdate(): bool
    {
        if (! $this->last_positions_updated_at) {
            return true;
        }

        return $this->last_positions_updated_at->diffInHours(now()) >= 24;
    }

    public function getVisibilityScore(): float
    {
        // Calculate visibility based on position distribution
        $positions = $this->keywords()
            ->whereNotNull('current_position')
            ->pluck('current_position');

        if ($positions->isEmpty()) {
            return 0.0;
        }

        $visibility = 0;
        foreach ($positions as $position) {
            if ($position <= 3) {
                $visibility += 1.0;
            } elseif ($position <= 10) {
                $visibility += 0.5;
            } elseif ($position <= 20) {
                $visibility += 0.1;
            }
        }

        return round(($visibility / $positions->count()) * 100, 2);
    }

    protected static function boot(): void
    {
        parent::boot();

        self::creating(function ($project): void {
            // Extract domain from URL
            if ($project->url && ! $project->domain) {
                $project->domain = parse_url($project->url, PHP_URL_HOST);
            }
        });
    }

    // Scopes
    #[Scope]
    protected function active($query)
    {
        return $query->where('is_active', true);
    }

    #[Scope]
    protected function forDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }
}
