<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'date_of_birth',
        'gender',
        'profession',
        'company_name',
        'country',
        'city',
        'address',
        'social_media_url',
        'registration_date',
        'status',
        'import_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'registration_date' => 'date',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    // Convenience accessors for alternative naming
    public function getPhoneAttribute(): string
    {
        return $this->phone_number;
    }

    public function getDobAttribute(): ?string
    {
        return $this->date_of_birth?->format('Y-m-d');
    }
}
