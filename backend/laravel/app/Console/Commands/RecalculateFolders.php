<?php

namespace App\Console\Commands;

use App\Services\FolderRecalculationService;
use Illuminate\Console\Command;

/**
 * Brings every stored folder back in line with the current requirement rules
 * (audit R-17).
 *
 * Settings runs this by itself after a change to which documents are
 * required, and the daily expiry check runs it after documents lapse. This is
 * for everything else: finishing a run that failed or was interrupted, and
 * once after deploying the fix, to repair folders left stale by changes made
 * before it existed. Only folders are written; no lifecycle status changes.
 */
class RecalculateFolders extends Command
{
    protected $signature = 'empower:recalculate-folders {--reason= : Recorded with the run}';

    protected $description = 'Recalculate every applicant folder from their verified documents and the current rules';

    public function handle(FolderRecalculationService $recalculations): int
    {
        $run = $recalculations->run(
            'manual',
            null,
            $this->option('reason') ?: 'Run from the command line.',
        );

        if ($run->status !== 'completed') {
            $this->error($run->error ?? 'The recalculation did not finish.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Checked %d applicant(s); %d folder(s) changed.',
            $run->applicants_checked,
            $run->applicants_changed,
        ));

        foreach ($run->summary ?? [] as $move => $count) {
            $this->line(sprintf('  %s: %d', str_replace('>', ' -> ', $move), $count));
        }

        return self::SUCCESS;
    }
}
