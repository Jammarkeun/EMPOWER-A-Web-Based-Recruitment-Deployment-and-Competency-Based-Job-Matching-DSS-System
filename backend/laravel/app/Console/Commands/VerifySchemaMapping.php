<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Checks that every Eloquent model resolves to a table that actually exists, and
 * that each declared fillable column is real.
 *
 * Laravel infers table names by pluralising the class name, which quietly fails
 * for mass nouns such as "education". Without a check like this the mistake only
 * surfaces at runtime, on whichever screen happens to touch that model first.
 */
class VerifySchemaMapping extends Command
{
    protected $signature = 'empower:verify-schema';

    protected $description = 'Verify that model table names and fillable columns match the database schema';

    public function handle(): int
    {
        $problems = 0;

        foreach ($this->models() as $class) {
            /** @var Model $model */
            $model = new $class();
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                $this->error(sprintf('%s expects table "%s", which does not exist.', class_basename($class), $table));
                $problems++;

                continue;
            }

            $columns = Schema::getColumnListing($table);
            $missing = array_diff($model->getFillable(), $columns);

            if ($missing !== []) {
                $this->error(sprintf(
                    '%s declares fillable column(s) not present on "%s": %s',
                    class_basename($class),
                    $table,
                    implode(', ', $missing)
                ));
                $problems++;

                continue;
            }

            $this->line(sprintf('  <fg=green>OK</> %-28s -> %s', class_basename($class), $table));
        }

        if ($problems > 0) {
            $this->newLine();
            $this->error("{$problems} model(s) do not match the schema.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All models map cleanly onto the schema.');

        return self::SUCCESS;
    }

    /** @return class-string<Model>[] */
    private function models(): array
    {
        return collect(File::files(app_path('Models')))
            ->map(fn ($file) => 'App\\Models\\'.$file->getFilenameWithoutExtension())
            ->filter(fn ($class) => class_exists($class) && is_subclass_of($class, Model::class))
            ->sort()
            ->values()
            ->all();
    }
}
