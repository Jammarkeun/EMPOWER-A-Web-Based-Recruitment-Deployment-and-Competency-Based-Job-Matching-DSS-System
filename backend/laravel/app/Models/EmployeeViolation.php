<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeViolation extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'violation_date',
        'violation_type',
        'description',
        'evidence_path',
        'issued_by',
        'remarks',
        'penalty',
        'status',
        'resolution_date',
    ];

    protected function casts(): array
    {
        return [
            'violation_date' => 'date',
            'resolution_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'under_review', 'escalated']);
    }
}
