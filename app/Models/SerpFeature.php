<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerpFeature extends Model
{
    use HasFactory, BelongsToTenant;

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
    public function scopeForFeatureType($query, string $type)
    {
        return $query->where('feature_type', $type);
    }

    public function scopeOwnedByUs($query)
    {
        return $query->where('is_our_domain', true);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }
}
