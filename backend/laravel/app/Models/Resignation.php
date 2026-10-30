<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resignation extends Model
{
    use HasFactory;

    /**
     * filed -> (info_requested -> filed) -> accepted -> completed, or withdrawn
     * from any open status (SeparationService).
     *
     * There is no "rejected". Under the Labor Code an employee may resign on a
     * month's notice, and CDE has not described a resignation it refused; a
     * request filed in error is closed as withdrawn, with who closed it and why.
     */
    public const STATUSES = ['filed', 'info_requested', 'accepted', 'completed', 'withdrawn'];

    protected $fillable = [
        'employee_id',
        'source',
        'filed_by',
        'deployment_id',
        'resignation_letter_path',
        'reason',
        'rendering_days',
        'filing_date',
        'exit_date',
        'requested_exit_date',
        'clearance_status',
        'status',
        'processed_by',
        'remarks',
        'employee_remarks',
        'employee_message',
        'withdrawn_by',
        'withdrawn_at',
        'withdrawal_reason',
    ];

    protected function casts(): array
    {
        return [
            'filing_date' => 'date:Y-m-d',
            'exit_date' => 'date:Y-m-d',
            'requested_exit_date' => 'date:Y-m-d',
            'rendering_days' => 'integer',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keeps the database's one-open-case guard in step with the status,
        // whichever path changed it (see the 2026_10_29_000600 migration).
        static::saving(function (Resignation $resignation) {
            $resignation->open_employee_id = $resignation->isOpen() ? $resignation->employee_id : null;
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SeparationEvent::class, 'case_id')
            ->where('case_type', 'resignation')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * A resignation may only be completed once the employee has an exit date and
     * has been cleared. Completing it early would release someone still holding
     * client property or an unreturned uniform. Not while HR is waiting on the
     * employee for information either: that question is answered first.
     */
    public function canBeCompleted(): bool
    {
        return in_array($this->status, ['filed', 'accepted'], true)
            && $this->clearance_status === 'cleared'
            && ! is_null($this->exit_date);
    }

    /**
     * Statuses in which a resignation is still being processed.
     *
     * A completed resignation has ended someone's employment and a withdrawn
     * one never will, so neither may be edited or completed again.
     */
    public const OPEN_STATUSES = ['filed', 'info_requested', 'accepted'];

    /**
     * Open and waiting on HR: to be reviewed, or accepted and being cleared.
     * The rest of the open ones (info_requested) wait on the employee's
     * answer, and the dashboard counts them as a figure of their own.
     */
    public const AWAITING_HR = ['filed', 'accepted'];

    /**
     * The moves HR may make on an open resignation. Completion has its own
     * endpoint and confirmation, so it is not here.
     */
    public const STAFF_TRANSITIONS = [
        'filed' => ['info_requested', 'accepted', 'withdrawn'],
        'info_requested' => ['filed', 'accepted', 'withdrawn'],
        // Back to "filed" corrects an acceptance made in error.
        'accepted' => ['filed', 'withdrawn'],
    ];

    /**
     * Statuses from which the employee may withdraw their own request.
     *
     * Once HR has accepted a resignation, Philippine practice treats it as no
     * longer the employee's alone to take back, so after acceptance a
     * withdrawal is agreed with HR and recorded by HR. Our default, pending
     * CDE's confirmation.
     */
    public const EMPLOYEE_WITHDRAWABLE = ['filed', 'info_requested'];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function canBeWithdrawnByEmployee(): bool
    {
        return in_array($this->status, self::EMPLOYEE_WITHDRAWABLE, true);
    }
}
