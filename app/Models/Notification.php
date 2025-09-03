<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'project_id',
        'keyword_id',
        'type',
        'severity',
        'title',
        'message',
        'data',
        'channel',
        'is_read',
        'is_sent',
        'sent_at',
        'read_at',
        'delivery_status',
    ];

    protected $casts = [
        'data' => 'array',
        'delivery_status' => 'array',
        'is_read' => 'boolean',
        'is_sent' => 'boolean',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    // Scopes
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    public function scopeUnsent($query)
    {
        return $query->where('is_sent', false);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    // Notification management methods
    public function markAsRead(): void
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    public function markAsSent(array $deliveryStatus = []): void
    {
        $this->update([
            'is_sent' => true,
            'sent_at' => now(),
            'delivery_status' => $deliveryStatus,
        ]);
    }

    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    public function isHigh(): bool
    {
        return $this->severity === 'high';
    }

    public function getIcon(): string
    {
        return match ($this->type) {
            'position_drop' => 'trending-down',
            'position_gain' => 'trending-up',
            'new_top_10' => 'star',
            'lost_top_10' => 'star-off',
            'featured_snippet_gained' => 'award',
            'featured_snippet_lost' => 'award-off',
            'competitor_activity' => 'users',
            'technical_issue' => 'alert-triangle',
            'report_ready' => 'file-text',
            'crawl_error' => 'x-circle',
            'system_alert' => 'bell',
            default => 'info'
        };
    }

    public function getColor(): string
    {
        return match ($this->severity) {
            'critical' => 'red',
            'high' => 'orange',
            'medium' => 'yellow',
            'low' => 'blue',
            default => 'gray'
        };
    }

    public function shouldSendEmail(): bool
    {
        return in_array($this->channel, ['email']) && 
               in_array($this->severity, ['critical', 'high']);
    }

    public function shouldSendPush(): bool
    {
        return in_array($this->channel, ['push', 'in_app']);
    }
}
