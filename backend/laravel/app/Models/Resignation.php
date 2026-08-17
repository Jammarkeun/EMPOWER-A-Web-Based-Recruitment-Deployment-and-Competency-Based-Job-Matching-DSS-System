<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resignation extends Model
{
    use HasFactory;

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
            'filing_date' => 'date',
            'exit_date' => 'date',
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
        return $this->clearance_status === 'cleared' && ! is_null($this->exit_date);
    }
}
