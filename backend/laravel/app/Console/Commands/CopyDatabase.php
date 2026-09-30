<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Copies every EMPOWER table from one database connection to another.
 *
 * This exists for one specific moment. The project is developed and demonstrated
 * against local MySQL because it is fast, and presented at the final defense
 * against Supabase. At some point the data has to move across, and doing that by
 * hand over thirty tables with foreign keys between them is exactly the sort of
 * job that goes wrong quietly.
 *
 *     php artisan empower:copy-database --from=mysql --to=pgsql
 *
 * The target must already have its schema; run the migrations there first. This
 * command moves rows, not structure.
 */
class CopyDatabase extends Command
{
    protected $signature = 'empower:copy-database
                            {--from= : Source connection, mysql or pgsql}
                            {--to= : Target connection, mysql or pgsql}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Copy all EMPOWER data from one database connection to another';

    /**
     * Tables deliberately left behind.
     *
     * These hold runtime state rather than records: a cached value, a queued
     * job, a login session. Copying them moves nothing of value and can cause
     * trouble, since a copied session points at a user id that may mean
     * something different on the other side.
     */
    private const SKIP = [
        'migrations', 'cache', 'cache_locks', 'sessions',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        if ($from === '' || $to === '' || $from === $to) {
            $this->error('Give two different connections, for example --from=mysql --to=pgsql');

            return self::FAILURE;
        }

        foreach ([$from, $to] as $name) {
            if (! config("database.connections.{$name}")) {
                $this->error("Unknown connection: {$name}");

                return self::FAILURE;
            }
        }

        try {
            $sourceDb = DB::connection($from)->getDatabaseName();
            $targetDb = DB::connection($to)->getDatabaseName();
        } catch (Throwable $e) {
            $this->error('Could not reach both databases: '.$e->getMessage());

            return self::FAILURE;
        }

        $tables = $this->tablesFor($from);

        if ($tables === []) {
            $this->error("No EMPOWER tables found on '{$from}'. Has it been migrated?");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("  From   : {$from}  ({$sourceDb})");
        $this->line("  To     : {$to}  ({$targetDb})");
        $this->line('  Tables : '.count($tables));
        $this->newLine();
        $this->warn('  Every listed table on the TARGET will be emptied first.');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Continue?', false)) {
            $this->line('  Cancelled.');

            return self::SUCCESS;
        }

        $target = DB::connection($to);

        $this->withoutForeignKeyChecks($target, function () use ($tables, $from, $to, $target) {
            foreach ($tables as $table) {
                if (! Schema::connection($to)->hasTable($table)) {
                    $this->line("  skipped  {$table} (not on the target, migrate it first)");

                    continue;
                }

                $target->table($table)->delete();

                $copied = 0;
                $source = DB::connection($from)->table($table);
                $hasId = Schema::connection($from)->hasColumn($table, 'id');

                $handler = function ($rows) use ($target, $table, &$copied) {
                    $target->table($table)->insert(
                        array_map(fn ($row) => (array) $row, $rows->all())
                    );
                    $copied += $rows->count();
                };

                // chunkById is safe against shifting result sets, but needs a
                // sortable key; the pivot tables have none, so they are read in
                // one go instead. They are small by nature.
                if ($hasId) {
                    $source->orderBy('id')->chunk(500, $handler);
                } else {
                    $rows = $source->get();
                    if ($rows->isNotEmpty()) {
                        $handler($rows);
                    }
                }

                $this->line(sprintf('  copied   %-34s %6d row(s)', $table, $copied));
            }
        });

        $this->resetSequences($to, $tables);

        $this->newLine();
        $this->info('  Done. Sign in against the target and spot-check before relying on it.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * EMPOWER's own tables on a connection, and nothing else.
     *
     * MariaDB's table listing returns every schema on the server, qualified as
     * "database.table". This machine also hosts unrelated project databases, so
     * anything outside the configured database is filtered out rather than
     * trusted. A copy command that reached into another project's data would be
     * a genuinely bad afternoon.
     *
     * @return string[]
     */
    private function tablesFor(string $connection): array
    {
        $database = DB::connection($connection)->getDatabaseName();

        return collect(Schema::connection($connection)->getTableListing())
            ->map(function (string $name) use ($database) {
                if (! str_contains($name, '.')) {
                    return $name;
                }

                [$schema, $table] = explode('.', $name, 2);

                // PostgreSQL qualifies with "public", MySQL with the database
                // name. Anything else belongs to another project.
                return in_array($schema, [$database, 'public'], true) ? $table : null;
            })
            ->filter()
            ->reject(fn (string $table) => in_array($table, self::SKIP, true))
            ->values()
            ->all();
    }

    /**
     * Foreign keys are switched off for the duration of the copy.
     *
     * The tables reference each other, so no single insert order satisfies all
     * of them, and building a dependency graph for a one-off copy is more
     * machinery than the job deserves.
     */
    private function withoutForeignKeyChecks($connection, callable $work): void
    {
        $isMysql = $connection->getDriverName() === 'mysql';

        if ($isMysql) {
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            $work();
        } finally {
            if ($isMysql) {
                $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    /**
     * Bring PostgreSQL's id sequences up past the copied rows.
     *
     * Without this the copy looks perfect and then the first new record fails
     * with a duplicate key error, because the sequence still sits at 1 while the
     * table already holds id 400. It is the classic way a cross-engine copy
     * appears to succeed while actually being broken.
     */
    private function resetSequences(string $connection, array $tables): void
    {
        if (DB::connection($connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $this->newLine();
        $this->line('  Resetting PostgreSQL id sequences');

        foreach ($tables as $table) {
            if (! Schema::connection($connection)->hasColumn($table, 'id')) {
                continue;
            }

            DB::connection($connection)->statement(
                'SELECT setval('
                ."    pg_get_serial_sequence('{$table}', 'id'),"
                ."    COALESCE((SELECT MAX(id) FROM {$table}), 1),"
                ."    (SELECT MAX(id) IS NOT NULL FROM {$table})"
                .')'
            );
        }

        $this->line('  Sequences aligned.');
    }
}
