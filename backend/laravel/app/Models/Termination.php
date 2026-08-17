<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Termination extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'reason',
        'termination_date',
        'documents_path',
        'remarks',
        'status',
        'approved_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'termination_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }
}
