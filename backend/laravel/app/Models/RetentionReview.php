<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's decision about a record on the retention review list: keep it on
 * file until a date. Written by RetentionReviewService only.
 */
class RetentionReview extends Model
{
    protected $fillable = [
        'category',
        'applicant_id',
        'decision',
        'note',
        'retain_until',
        'policy_value',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'retain_until' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
            'policy_value' => 'integer',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
