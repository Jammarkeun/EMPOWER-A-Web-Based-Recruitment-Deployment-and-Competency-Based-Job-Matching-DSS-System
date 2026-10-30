<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Moves stored documents from one disk to another, verifying every copy.
 *
 * Written for audit R-01: local documents used to live on the `public` disk,
 * where anybody with a link could open them for ever. This copies them to the
 * private disk under the same keys - so no database row needs to change - and,
 * only when asked, removes the public copies once each one has been verified.
 *
 * Also the way to carry local demo files to the Supabase bucket, since
 * `empower:copy-database` moves rows but not files.
 *
 * Copy first, check, then run again with --delete-source. Nothing is deleted
 * unless the copy on the target matches the source byte for byte.
 */
class MoveDocuments extends Command
{
    protected $signature = 'empower:move-documents
        {--from=public : Disk the documents are on now}
        {--to= : Private disk to move them to (defaults to the configured document disk)}
        {--delete-source : Remove each source file after its copy is verified}
        {--dry-run : List what would be copied without copying anything}';

    protected $description = 'Copy applicant documents, evidence and photos to the private document disk, verifying each one';

    /** The folders the application writes documents to. Nothing else is touched. */
    private const FOLDERS = [
        'requirements/',
        'violations/',
        'violation-reports/',
        'resignations/',
        'terminations/',
        'profile-photos/',
    ];

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) ($this->option('to') ?: config('empower.uploads.disk'));
        $targetConfig = config("filesystems.disks.{$to}");

        if (! is_array($targetConfig) || ! is_array(config("filesystems.disks.{$from}"))) {
            $this->error('Both disks must be configured in config/filesystems.php.');

            return self::FAILURE;
        }

        if ($to === 'public' || ($targetConfig['visibility'] ?? null) === 'public') {
            $this->error("Refusing to move documents onto \"{$to}\": it is a public disk.");

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->error('The source and target disks are the same.');

            return self::FAILURE;
        }

        $source = Storage::disk($from);
        $target = Storage::disk($to);

        $files = collect($source->allFiles())
            ->filter(fn (string $path) => collect(self::FOLDERS)->contains(fn ($folder) => str_starts_with($path, $folder)))
            ->values();

        $this->info(sprintf('%d document file(s) on "%s" to place on "%s".', $files->count(), $from, $to));

        $copied = $skipped = $deleted = $failed = 0;

        foreach ($files as $path) {
            if ($this->option('dry-run')) {
                $this->line("  would copy {$path}");

                continue;
            }

            try {
                $sourceHash = hash('sha256', $source->get($path));

                if ($target->exists($path) && hash('sha256', $target->get($path)) === $sourceHash) {
                    $skipped++;
                } else {
                    $stream = $source->readStream($path);
                    $target->writeStream($path, $stream, ['visibility' => 'private']);

                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    if (hash('sha256', $target->get($path)) !== $sourceHash) {
                        $failed++;
                        $this->error("  copy of {$path} did not verify; source kept");

                        continue;
                    }

                    $copied++;
                }

                if ($this->option('delete-source')) {
                    $source->delete($path);
                    $deleted++;
                }
            } catch (Throwable $exception) {
                $failed++;
                // The key alone identifies the file; the provider's message can
                // name endpoints and is not needed on the console.
                $this->error("  could not move {$path}");
                report($exception);
            }
        }

        if (! $this->option('dry-run')) {
            $this->info("Copied {$copied}, already present {$skipped}, source copies removed {$deleted}, failed {$failed}.");

            if (! $this->option('delete-source') && $copied + $skipped > 0) {
                $this->warn('Source files were kept. Switch DOCUMENT_DISK, check a document opens, then run again with --delete-source.');
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
