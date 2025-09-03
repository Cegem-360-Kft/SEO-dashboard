<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SerpFeature extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'keyword_id',
        'date',
        'search_engine',
        'device',
        'feature_type',
        'position',
        'domain',
        'title',
        'description',
        'url',
        'data',
        'is_our_domain',
    ];

    protected $casts = [
        'date' => 'date',
        'data' => 'array',
        'is_our_domain' => 'boolean',
    ];

    // Relationships
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    // Scopes
    #[Scope]
    protected function forFeatureType($query, string $type)
    {
        return $query->where('feature_type', $type);
    }

    #[Scope]
    protected function ownedByUs($query)
    {
        return $query->where('is_our_domain', true);
    }

    #[Scope]
    protected function forDate($query, $date)
    {
        return $query->where('date', $date);
    }

    #[Scope]
    protected function forDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }
}
