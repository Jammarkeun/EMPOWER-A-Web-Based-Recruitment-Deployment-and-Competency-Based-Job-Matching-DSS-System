<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The concurrency tests commit real rows and wipe the schema between runs, so
 * they must only ever touch a disposable database. This is the check, shared
 * by the test and by every worker process it starts: the default connection
 * must be a PostgreSQL or MariaDB server on this machine (127.0.0.1 or
 * localhost) and the database must be named exactly `empower_test`.
 *
 * The name is what keeps the wipe away from real data. On the XAMPP server it
 * means a separate database beside `empower` - created once with
 * `CREATE DATABASE empower_test` - so the demo data and the other projects'
 * databases on that server are never touched. Supabase is not on this machine
 * and is always refused. The project does not use Docker.
 */
final class ThrowawayDatabase
{
    public const DATABASE = 'empower_test';

    public const HOSTS = ['127.0.0.1', 'localhost'];

    public static function problem(): ?string
    {
        $config = config('database.connections.'.config('database.default'));
        $driver = $config['driver'] ?? null;

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return "needs PostgreSQL or MariaDB, not {$driver}";
        }

        if (! in_array($config['host'] ?? null, self::HOSTS, true)) {
            return 'the default connection is not a database server on this machine';
        }

        if (($config['database'] ?? null) !== self::DATABASE) {
            return 'the database is not the throwaway `'.self::DATABASE.'` database';
        }

        return null;
    }

    public static function assert(): void
    {
        if ($problem = self::problem()) {
            throw new RuntimeException("Refusing to run: {$problem}.");
        }

        // A query, so a misconfigured connection fails here and not halfway
        // through a test.
        DB::select('select 1');
    }
}
