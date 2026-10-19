<?php

namespace App\Console\Commands;

use App\Models\ApplicantRequirement;
use App\Models\User;
use App\Notifications\DocumentsExpiringSoon;
use App\Services\FolderRecalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Flags verified documents that are about to lapse, or already have.
 *
 * Expiry is otherwise entirely passive: an applicant sits in the deployment-ready
 * folder while their police clearance quietly goes stale, and nobody finds out
 * until the client asks for the paperwork. Folder categorisation already ignores
 * expired documents, so the applicant silently drops out of the deployable pool
 * with no explanation unless something says so out loud.
 *
 * Each run ends by recalculating stored folders (audit R-17). The folder rule
 * already ignored a lapsed document, but the folder kept on file only changed
 * when somebody touched the applicant's own documents, so an applicant whose
 * clearance lapsed stayed counted in Folder 1 indefinitely.
 *
 * Scheduled daily. See routes/console.php.
 */
class CheckExpiringDocuments extends Command
{
    protected $signature = 'empower:check-expiring-documents {--days= : Warn this many days ahead}';

    protected $description = 'Notify HR of verified documents that are expiring or have expired';

    public function handle(FolderRecalculationService $recalculations): int
    {
        // Falls back to the configured window, which an administrator can change
        // from the settings screen, so the schedule does not have to be edited
        // to alter how far ahead the warning looks.
        $days = (int) ($this->option('days') ?: config('empower.expiry_warning_days', 30));
        $horizon = now()->addDays($days);

        $expiring = ApplicantRequirement::query()
            ->where('status', 'verified')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now())
            ->whereDate('expiry_date', '<=', $horizon)
            ->count();

        $expired = ApplicantRequirement::query()
            ->where('status', 'verified')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now())
            ->count();

        if ($expiring === 0 && $expired === 0) {
            $this->info('No documents expiring or expired. Nothing to report.');

            // Still run: it costs little and repairs any folder left stale by
            // something else, so the daily check doubles as a safety net.
            return $this->recalculateFolders($recalculations, 0);
        }

        // Marking lapsed documents as expired keeps folder categorisation honest
        // without waiting for someone to open the applicant's record.
        $reclassified = ApplicantRequirement::query()
            ->where('status', 'verified')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);

        // Addressed to staff who can act on it. Applicants are not told their own
        // clearance lapsed by an automated message; that conversation belongs to
        // HR.
        $recipients = User::query()
            ->whereIn('user_type', ['admin', 'hr'])
            ->where('is_active', true)
            ->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new DocumentsExpiringSoon($expiring, $expired, $days));
        }

        $this->warn(sprintf(
            '%d document(s) expiring within %d days, %d already expired (%d reclassified). Notified %d staff member(s).',
            $expiring,
            $days,
            $expired,
            $reclassified,
            $recipients->count(),
        ));

        DB::table('audit_logs')->insert([
            'actor_user_id' => null,
            'action_type' => 'update',
            'module_key' => 'requirements',
            'record_type' => 'ApplicantRequirement',
            'new_values_json' => json_encode([
                'scheduled_check' => 'expiring_documents',
                'expiring' => $expiring,
                'expired' => $expired,
                'reclassified' => $reclassified,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->recalculateFolders($recalculations, $reclassified);
    }

    /**
     * Only folders are written; an applicant's lifecycle status is untouched.
     * A failure is recorded on the run and fails the command, so the scheduler
     * reports it instead of it passing unnoticed.
     */
    private function recalculateFolders(FolderRecalculationService $recalculations, int $lapsed): int
    {
        $run = $recalculations->run(
            'document_expiry',
            null,
            $lapsed > 0
                ? "Daily expiry check: {$lapsed} verified document(s) lapsed."
                : 'Daily expiry check.',
        );

        if ($run->status !== 'completed') {
            $this->error('Folder recalculation did not finish: '.$run->error);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Folders recalculated: %d checked, %d changed.',
            $run->applicants_checked,
            $run->applicants_changed,
        ));

        return self::SUCCESS;
    }
}
