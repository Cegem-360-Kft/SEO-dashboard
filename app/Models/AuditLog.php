<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuditLog extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'url',
        'ip_address',
        'user_agent',
        'tags',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'tags' => 'array',
    ];

    /**
     * Log a security event
     */
    public static function logSecurityEvent(string $event, array $data = [], ?Model $auditable = null): self
    {
        return self::query()->create([
            'tenant_id' => auth()->user()?->tenant_id,
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'new_values' => $data,
            'url' => request()?->fullUrl(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'tags' => ['security'],
        ]);
    }

    /**
     * Log an authentication event
     */
    public static function logAuthEvent(string $event, ?User $user = null, array $data = []): self
    {
        return self::query()->create([
            'tenant_id' => $user?->tenant_id,
            'user_id' => $user?->id,
            'event' => $event,
            'new_values' => $data,
            'url' => request()?->fullUrl(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'tags' => ['authentication'],
        ]);
    }

    /**
     * Log a data access event
     */
    public static function logDataAccess(string $resource, string $action, ?Model $auditable = null): self
    {
        return self::query()->create([
            'tenant_id' => auth()->user()?->tenant_id,
            'user_id' => auth()->id(),
            'event' => sprintf('%s.%s', $resource, $action),
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'url' => request()?->fullUrl(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'tags' => ['data_access', $resource],
        ]);
    }

    /**
     * Get the user that caused the audit log
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the auditable model
     */
    public function auditable()
    {
        return $this->morphTo();
    }
}
