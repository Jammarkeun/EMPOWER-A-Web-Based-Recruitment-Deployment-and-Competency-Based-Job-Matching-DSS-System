<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'application_status_history';

    protected $fillable = [
        'applicant_id',
        'from_status',
        'to_status',
        'reason',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * The exact reasons the old deployment code wrote when it walked an
     * applicant through endorsement and approval on its own (audit R-11).
     * Nothing else ever wrote these words.
     */
    public const AUTO_WRITTEN_AT_DEPLOYMENT = [
        'Endorsed to the client company for evaluation.',
        'Approved by the client company.',
    ];

    /**
     * Whether this row was written automatically by an earlier deployment
     * rather than recorded by anybody. Such rows stay in the history as they
     * are - nothing is rewritten - but are never presented as a genuine
     * endorsement or client approval.
     */
    public function wasWrittenAutomaticallyAtDeployment(): bool
    {
        return in_array($this->to_status, ['client_evaluation', 'approved'], true)
            && in_array($this->reason, self::AUTO_WRITTEN_AT_DEPLOYMENT, true);
    }

    /** What to show beside such a row so nobody reads it as a real approval. */
    public const AUTO_WRITTEN_NOTE = 'Written automatically when a deployment was recorded; '
        .'no endorsement or client approval was separately recorded.';
}
