<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KeywordPosition extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'keyword_id',
        'date',
        'search_engine',
        'device',
        'position',
        'url',
        'serp_features',
        'estimated_traffic',
        'estimated_value',
        'is_featured_snippet',
        'is_local_pack',
        'is_paid_above',
        'ads_count',
        'serp_title',
        'serp_description',
        'checked_at',
    ];

    protected $casts = [
        'date' => 'date',
        'serp_features' => 'array',
        'estimated_value' => 'decimal:2',
        'is_featured_snippet' => 'boolean',
        'is_local_pack' => 'boolean',
        'is_paid_above' => 'boolean',
        'checked_at' => 'datetime',
    ];

    // Relationships
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    // Analytics methods
    public function hasImproved(?self $previous = null): bool
    {
        if (! $previous instanceof self) {
            return false;
        }

        return $this->position < $previous->position;
    }

    public function hasDeclined(?self $previous = null): bool
    {
        if (! $previous instanceof self) {
            return false;
        }

        return $this->position > $previous->position;
    }

    public function getPositionChange(?self $previous = null): int
    {
        if (! $previous instanceof self) {
            return 0;
        }

        return $previous->position - $this->position;
    }

    public function isRanking(): bool
    {
        return $this->position !== null;
    }

    public function getVisibilityScore(): float
    {
        if (! $this->position) {
            return 0.0;
        }

        return match (true) {
            $this->position <= 3 => 100.0,
            $this->position <= 10 => 50.0,
            $this->position <= 20 => 10.0,
            default => 1.0
        };
    }

    public function getCtrEstimate(): float
    {
        if (! $this->position) {
            return 0.0;
        }

        // Industry-standard CTR curve
        $ctrCurve = [
            1 => 31.49, 2 => 15.55, 3 => 10.06, 4 => 6.97, 5 => 5.13,
            6 => 4.03, 7 => 3.29, 8 => 2.76, 9 => 2.38, 10 => 2.08,
        ];

        return $ctrCurve[$this->position] ?? 1.0;
    }

    public function hasSpecialFeature(): bool
    {
        return $this->is_featured_snippet ||
               $this->is_local_pack ||
               ! empty($this->serp_features);
    }

    // Scopes
    #[Scope]
    protected function forDate($query, $date)
    {
        return $query->where('date', $date);
    }

    #[Scope]
    protected function forSearchEngine($query, string $engine)
    {
        return $query->where('search_engine', $engine);
    }

    #[Scope]
    protected function forDevice($query, string $device)
    {
        return $query->where('device', $device);
    }

    #[Scope]
    protected function inTop10($query)
    {
        return $query->whereBetween('position', [1, 10]);
    }

    #[Scope]
    protected function withFeaturedSnippet($query)
    {
        return $query->where('is_featured_snippet', true);
    }

    #[Scope]
    protected function withLocalPack($query)
    {
        return $query->where('is_local_pack', true);
    }

    #[Scope]
    protected function forDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }
}
