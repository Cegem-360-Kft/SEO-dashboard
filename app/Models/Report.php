<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Report extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'user_id',
        'name',
        'description',
        'type',
        'frequency',
        'config',
        'recipients',
        'format',
        'is_active',
        'is_automated',
        'period_start',
        'period_end',
        'last_generated_at',
        'next_generation_at',
        'file_path',
        'file_size',
        'status',
        'error_message',
    ];

    protected $casts = [
        'config' => 'array',
        'recipients' => 'array',
        'is_active' => 'boolean',
        'is_automated' => 'boolean',
        'period_start' => 'date',
        'period_end' => 'date',
        'last_generated_at' => 'datetime',
        'next_generation_at' => 'datetime',
    ];

    // Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAutomated($query)
    {
        return $query->where('is_automated', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeDueForGeneration($query)
    {
        return $query->where('is_automated', true)
                    ->where('is_active', true)
                    ->where('next_generation_at', '<=', now());
    }

    // Report management methods
    public function markAsGenerating(): void
    {
        $this->update([
            'status' => 'generating',
            'error_message' => null,
        ]);
    }

    public function markAsCompleted(string $filePath, int $fileSize): void
    {
        $this->update([
            'status' => 'completed',
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'last_generated_at' => now(),
            'next_generation_at' => $this->calculateNextGeneration(),
            'error_message' => null,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function calculateNextGeneration(): ?\Carbon\Carbon
    {
        if (!$this->is_automated || !$this->is_active) {
            return null;
        }

        return match ($this->frequency) {
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            'quarterly' => now()->addMonths(3),
            default => null,
        };
    }

    public function getFileSizeFormatted(): string
    {
        if (!$this->file_size) {
            return 'Unknown';
        }

        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    public function isDue(): bool
    {
        return $this->is_automated && 
               $this->is_active && 
               $this->next_generation_at && 
               $this->next_generation_at->isPast();
    }
}
