<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a DTR submission's life: who moved it, from what to what, why,
 * and through which import. Written only by DtrSubmissionService.
 */
class DtrSubmissionEvent extends Model
{
    protected $fillable = [
        'dtr_submission_id',
        'from_status',
        'to_status',
        'note',
        'attendance_import_id',
        'actor_id',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(DtrSubmission::class, 'dtr_submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(AttendanceImport::class, 'attendance_import_id');
    }
}
