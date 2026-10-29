<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportChunk extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'import_id',
        'chunk_number',
        'start_row',
        'end_row',
        'status',
        'total_rows',
        'processed_rows',
        'saved_rows',
        'rejected_rows',
        'attempts',
        'error_message',
        'claimed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_number' => 'integer',
            'start_row' => 'integer',
            'end_row' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'saved_rows' => 'integer',
            'rejected_rows' => 'integer',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
