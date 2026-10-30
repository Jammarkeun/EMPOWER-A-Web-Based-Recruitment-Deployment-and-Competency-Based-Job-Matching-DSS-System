<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One note on an incident report, by management or the coordinator.
 * Written only by IncidentReportService, never edited or deleted.
 */
class IncidentReportNote extends Model
{
    protected $guarded = ['id'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(IncidentReport::class, 'incident_report_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
