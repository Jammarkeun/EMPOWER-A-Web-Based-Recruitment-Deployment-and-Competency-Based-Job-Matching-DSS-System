<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a requisition's status. Written only by
 * ManpowerRequisitionService, never edited.
 */
class ManpowerRequisitionStatusHistory extends Model
{
    protected $table = 'manpower_requisition_status_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(ManpowerRequisition::class, 'manpower_requisition_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
