<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'Queued';

    public const STATUS_PROCESSING = 'Processing';

    public const STATUS_COMPLETED = 'Completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'Completed with Errors';

    public const STATUS_FAILED = 'Failed';

    protected $fillable = [
        'user_id',
        'filename',
        'file_path',
        'status',
        'total_rows',
        'processed_rows',
        'validated_rows',
        'valid_rows',
        'invalid_rows',
        'duplicate_rows',
        'saved_rows',
        'rejected_rows',
        'started_at',
        'completed_at',
        'duration_seconds',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'validated_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'saved_rows' => 'integer',
            'rejected_rows' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_seconds' => 'float',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(ImportChunk::class);
    }

    public function rowErrors(): HasMany
    {
        return $this->hasMany(ImportRowError::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark import as terminally failed with sanitized reason and finalized duration.
     * Idempotent: repeated calls will not alter an already finalized failure or duration.
     */
    public function markFailed(string $failureReason): bool
    {
        // If already in a terminal state, do not overwrite finalized metadata or corrupt duration
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS, self::STATUS_FAILED], true)) {
            return false;
        }

        $completedAt = now();
        $startTime = $this->started_at ?? $this->created_at ?? $completedAt;
        $duration = max(0.0, abs(round($completedAt->diffInRealSeconds($startTime), 2)));

        $this->update([
            'status' => self::STATUS_FAILED,
            'failure_reason' => $failureReason,
            'completed_at' => $completedAt,
            'duration_seconds' => $duration,
        ]);

        return true;
    }
}
