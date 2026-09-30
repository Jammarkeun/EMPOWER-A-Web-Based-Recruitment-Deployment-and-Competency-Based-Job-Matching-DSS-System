<?php

namespace App\Console\Commands;

use App\Models\ApplicantRequirement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CheckDocumentStorage extends Command
{
    protected $signature = 'empower:check-document-storage {--delete : Remove database metadata for missing objects}';

    protected $description = 'Find applicant document records whose private storage object is missing';

    public function handle(): int
    {
        $disk = Storage::disk(config('empower.uploads.disk', 'supabase'));
        $missing = 0;

        ApplicantRequirement::query()
            ->whereNotNull('file_path')
            ->select(['id', 'file_path'])
            ->chunkById(100, function ($requirements) use ($disk, &$missing) {
                foreach ($requirements as $requirement) {
                    /** @var ApplicantRequirement $requirement */
                    if ($disk->exists($requirement->file_path)) {
                        continue;
                    }

                    $missing++;
                    $this->warn("Missing document {$requirement->id}: {$requirement->file_path}");

                    if ($this->option('delete')) {
                        $requirement->forceFill([
                            'file_path' => null,
                            'file_name' => null,
                            'file_mime' => null,
                            'file_size_bytes' => null,
                            'file_hash' => null,
                        ])->saveQuietly();
                    }
                }
            });

        $this->info("Storage check complete. Missing objects: {$missing}.");

        return $missing > 0 && ! $this->option('delete') ? self::FAILURE : self::SUCCESS;
    }
}
