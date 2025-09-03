<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class Tenant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'domain',
        'settings',
        'branding',
        'plan',
        'max_projects',
        'max_keywords',
        'max_users',
        'is_active',
        'trial_ends_at',
        'subscription_ends_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'branding' => 'array',
        'is_active' => 'boolean',
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
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

    // Helper methods for plan limitations
    public function canCreateProject(): bool
    {
        return $this->projects()->count() < $this->max_projects;
    }

    public function canAddKeywords(int $count = 1): bool
    {
        return $this->keywords()->count() + $count <= $this->max_keywords;
    }

    public function canAddUsers(int $count = 1): bool
    {
        return $this->users()->count() + $count <= $this->max_users;
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && now()->lte($this->trial_ends_at);
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscription_ends_at && now()->lte($this->subscription_ends_at);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Generate a unique identifier for the tenant
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function boot(): void
    {
        parent::boot();

        self::creating(function ($tenant): void {
            if (empty($tenant->uuid)) {
                $tenant->uuid = Str::uuid();
            }

            if (empty($tenant->slug)) {
                $tenant->slug = Str::slug($tenant->name);
            }
        });
    }
}
