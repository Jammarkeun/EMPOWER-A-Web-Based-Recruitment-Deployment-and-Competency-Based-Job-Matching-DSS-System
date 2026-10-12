<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the moments already on record onto the application's clock.
 *
 * Until now config/app.php ignored APP_TIMEZONE and ran on UTC, so every
 * created_at, submitted_at, read_at and the like was written as a UTC wall
 * time, and "today" was yesterday between midnight and 8 AM in Manila. With the
 * application now on APP_TIMEZONE, those stored values would read 8 hours
 * early; this adds the zone's offset to each of them once, so old and new rows
 * agree.
 *
 * Only columns that record when something happened are moved. Date-only
 * columns hold calendar dates, and the DTR's time in and time out are clock
 * times as HR typed them, so they already read correctly. The tables are those
 * of the connection's own schema; on Supabase that keeps auth and storage out
 * of reach.
 */
return new class extends Migration
{
    /** Clock times entered by HR, not moments recorded by the application. */
    private const WALL_CLOCK = [
        'attendance_records.time_in',
        'attendance_records.time_out',
    ];

    public function up(): void
    {
        $this->shift(1);
    }

    public function down(): void
    {
        $this->shift(-1);
    }

    private function shift(int $direction): void
    {
        $minutes = CarbonImmutable::now(config('app.timezone'))->utcOffset() * $direction;

        if ($minutes === 0) {
            return;
        }

        $driver = DB::getDriverName();

        foreach (Schema::getTables(Schema::getCurrentSchemaName()) as $table) {
            $columns = collect(Schema::getColumns($table['name']))
                ->filter(fn (array $column) => $this->recordsAMoment($driver, $column))
                ->pluck('name')
                ->reject(fn (string $column) => in_array("{$table['name']}.{$column}", self::WALL_CLOCK, true));

            if ($columns->isEmpty()) {
                continue;
            }

            // One statement per table: every timestamp column is set together,
            // so a column with an ON UPDATE clause cannot overwrite itself.
            DB::table($table['name'])->update(
                $columns->mapWithKeys(fn (string $column) => [
                    $column => DB::raw($this->plusMinutes($driver, $column, $minutes)),
                ])->all()
            );
        }
    }

    /**
     * Laravel's timestamp() columns. dateTime() is the same type on PostgreSQL
     * and SQLite, which is why the DTR columns are also excluded by name.
     */
    private function recordsAMoment(string $driver, array $column): bool
    {
        $type = strtolower($column['type_name']);

        return $driver === 'sqlite'
            ? in_array($type, ['datetime', 'timestamp'], true)
            : $type === 'timestamp';
    }

    private function plusMinutes(string $driver, string $column, int $minutes): string
    {
        $wrapped = DB::getQueryGrammar()->wrap($column);

        return match ($driver) {
            'pgsql' => "{$wrapped} + interval '{$minutes} minutes'",
            'sqlite' => "datetime({$wrapped}, '".sprintf('%+d', $minutes)." minutes')",
            default => "{$wrapped} + INTERVAL {$minutes} MINUTE",
        };
    }
};
