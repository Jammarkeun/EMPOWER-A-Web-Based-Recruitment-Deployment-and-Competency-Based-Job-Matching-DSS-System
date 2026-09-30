<?php

namespace App\Services;

use App\Models\ApplicantRequirement;
use App\Models\User;

/**
 * Records that a member of staff opened an applicant's document.
 *
 * There are two places an officer can open a document - from the applicant's own
 * record, and from the document library that groups files by kind - and both
 * must count as the same thing. Keeping the behaviour here rather than in each
 * controller is what stops a document read from one screen entering review
 * while the same document read from the other quietly does not.
 *
 * This is the event behind the applicant-facing distinction between "upload
 * successful" and "being checked". Nothing else sets it: not storing the file,
 * not the upload returning success, and not the applicant opening their own
 * copy.
 */
class DocumentReviewService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * @return bool whether this was the first time anyone opened it
     */
    public function recordStaffView(ApplicantRequirement $requirement, User $staff): bool
    {
        $isFirst = $requirement->markFirstViewedBy($staff);

        // Audited every time, not only the first. The first view answers "has
        // review begun?"; the audit trail answers the separate and, for a file
        // covered by RA 10173, more important question of who has looked at
        // somebody's birth certificate and when.
        $this->audit->record(
            action: 'view',
            module: 'requirements',
            recordType: ApplicantRequirement::class,
            recordId: $requirement->id,
            newValues: [
                'document' => $requirement->file_name,
                'applicant_id' => $requirement->applicant_id,
                'entered_review' => $isFirst,
            ],
        );

        return $isFirst;
    }
}
