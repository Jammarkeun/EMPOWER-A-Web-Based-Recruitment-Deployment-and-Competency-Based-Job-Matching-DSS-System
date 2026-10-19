<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resignation extends Model
{
    use HasFactory;

    /** filed -> accepted -> completed, or withdrawn (SeparationService). */
    public const STATUSES = ['filed', 'accepted', 'completed', 'withdrawn'];

    protected $fillable = [
        'employee_id',
        'resignation_letter_path',
        'reason',
        'rendering_days',
        'filing_date',
        'exit_date',
        'clearance_status',
        'status',
        'processed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'filing_date' => 'date:Y-m-d',
            'exit_date' => 'date:Y-m-d',
            'rendering_days' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * A resignation may only be completed once the employee has an exit date and
     * has been cleared. Completing it early would release someone still holding
     * client property or an unreturned uniform.
     */
    public function canBeCompleted(): bool
    {
        return $this->isOpen()
            && $this->clearance_status === 'cleared'
            && ! is_null($this->exit_date);
    }

    /**
     * Statuses in which a resignation is still being processed.
     *
     * A completed resignation has ended someone's employment and a withdrawn
     * one never will, so neither may be edited or completed again.
     */
    public const OPEN_STATUSES = ['filed', 'accepted'];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
