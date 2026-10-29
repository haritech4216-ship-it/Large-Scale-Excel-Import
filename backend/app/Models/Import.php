<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'filename',
        'file_path',
        'status',
        'total_rows',
        'processed_rows',
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
}
