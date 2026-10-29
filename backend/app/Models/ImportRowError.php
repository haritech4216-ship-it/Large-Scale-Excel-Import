<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRowError extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_id',
        'excel_row',
        'customer_id',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'excel_row' => 'integer',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
