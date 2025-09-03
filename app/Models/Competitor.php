<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Competitor extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'name',
        'domain',
        'url',
        'description',
        'priority',
        'categories',
        'estimated_traffic',
        'domain_authority',
        'backlinks_count',
        'estimated_value',
        'top_keywords',
        'shared_keywords_count',
        'visibility_score',
        'is_active',
        'last_analyzed_at',
    ];

    protected $casts = [
        'categories' => 'array',
        'estimated_value' => 'decimal:2',
        'top_keywords' => 'array',
        'shared_keywords_count' => 'array',
        'visibility_score' => 'decimal:4',
        'is_active' => 'boolean',
        'last_analyzed_at' => 'datetime',
    ];

    // Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    // Analytics methods
    public function needsAnalysis(): bool
    {
        if (!$this->last_analyzed_at) {
            return true;
        }
        
        return $this->last_analyzed_at->diffInDays(now()) >= 7;
    }

    public function getSharedKeywordsCount(): int
    {
        return is_array($this->shared_keywords_count) ? 
               array_sum($this->shared_keywords_count) : 0;
    }

    public function getCompetitiveStrength(): string
    {
        $score = 0;
        
        if ($this->domain_authority > 70) $score += 3;
        elseif ($this->domain_authority > 50) $score += 2;
        elseif ($this->domain_authority > 30) $score += 1;
        
        if ($this->estimated_traffic > 100000) $score += 3;
        elseif ($this->estimated_traffic > 10000) $score += 2;
        elseif ($this->estimated_traffic > 1000) $score += 1;
        
        if ($this->visibility_score > 80) $score += 2;
        elseif ($this->visibility_score > 50) $score += 1;
        
        return match (true) {
            $score >= 7 => 'very_strong',
            $score >= 5 => 'strong',
            $score >= 3 => 'moderate',
            default => 'weak'
        };
    }
}
