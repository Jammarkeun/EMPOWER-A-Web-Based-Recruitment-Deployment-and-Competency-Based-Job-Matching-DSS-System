<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Termination extends Model
{
    use HasFactory;

    /** draft | for_review -> finalized (administrator) or withdrawn (SeparationService). */
    public const STATUSES = ['draft', 'for_review', 'finalized', 'withdrawn'];

    protected $fillable = [
        'employee_id',
        'reason',
        'termination_date',
        'documents_path',
        'remarks',
        'status',
        'approved_by',
        'created_by',
        'finalized_at',
        'withdrawn_by',
        'withdrawn_at',
        'withdrawal_reason',
    ];

    protected function casts(): array
    {
        return [
            'termination_date' => 'date:Y-m-d',
            'finalized_at' => 'datetime',
            'withdrawn_at' => 'datetime',
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

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    /**
     * Statuses in which a termination is still awaiting its outcome.
     *
     * Review ends one of two ways: an administrator finalises it, or it is
     * withdrawn because the grounds did not hold. Either way it is closed and
     * stays on file exactly as it stood.
     */
    public const OPEN_STATUSES = ['draft', 'for_review'];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
