<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        // Automatically scope queries to current tenant
        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (auth()->check() && auth()->user()->tenant_id) {
                $builder->where('tenant_id', auth()->user()->tenant_id);
            }
        });

        // Automatically set tenant_id when creating records
        static::creating(function ($model): void {
            if (auth()->check() && auth()->user()->tenant_id && ! $model->tenant_id) {
                $model->tenant_id = auth()->user()->tenant_id;
            }
        });
    }

    // Method to bypass tenant scoping (use with caution)
    public static function withoutTenantScope()
    {
        return static::withoutGlobalScope('tenant');
    }

    // Method to manually set tenant scope
    public static function forTenant(int $tenantId)
    {
        return static::withoutGlobalScope('tenant')->where('tenant_id', $tenantId);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
