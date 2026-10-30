<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Closes EMPOWER's tables to Supabase's public Data API.
 *
 * Supabase publishes every table in the `public` schema through its REST API.
 * Who may use it is decided by row-level security and by the grants held by
 * the `anon` and `authenticated` roles - and on the defence project, checked on
 * 15 October 2026, RLS was off on all 50 tables while both roles held SELECT,
 * INSERT, UPDATE and DELETE on every one. Anybody holding the project's anon
 * (publishable) key, which Supabase treats as public, could have read the
 * users, applicants and documents tables, or changed and deleted rows, without
 * going through Laravel at all.
 *
 * EMPOWER never uses that API: Laravel connects as the table owner (`postgres`,
 * which bypasses RLS), and the browser talks only to Laravel. So the fix takes
 * nothing away from the application. It turns RLS on with no policies (deny by
 * default), revokes the two roles' table and sequence privileges, and stops
 * future tables created by the migrations from being granted to them.
 *
 * PostgreSQL only, and only where those Supabase roles exist; on MySQL, SQLite,
 * or a plain PostgreSQL server this does nothing.
 */
return new class extends Migration
{
    private const API_ROLES = ['anon', 'authenticated'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $schema = DB::selectOne('select current_schema() as name')->name;

        foreach ($this->tables($schema) as $table) {
            DB::statement(sprintf('alter table %s.%s enable row level security', $this->ident($schema), $this->ident($table)));
        }

        foreach ($this->existingRoles() as $role) {
            $role = $this->ident($role);
            $quotedSchema = $this->ident($schema);

            DB::statement("revoke all on all tables in schema {$quotedSchema} from {$role}");
            DB::statement("revoke all on all sequences in schema {$quotedSchema} from {$role}");
            // Tables and sequences the migrations create later, as this role.
            DB::statement("alter default privileges in schema {$quotedSchema} revoke all on tables from {$role}");
            DB::statement("alter default privileges in schema {$quotedSchema} revoke all on sequences from {$role}");
        }
    }

    /**
     * Deliberately does nothing.
     *
     * Reversing this would reopen every table to anybody with the anon key. If
     * a future feature genuinely needs the Data API, it should add a policy for
     * that one table rather than undo the lock for all of them.
     */
    public function down(): void
    {
    }

    /** @return list<string> */
    private function tables(string $schema): array
    {
        return array_map(
            fn ($row) => $row->tablename,
            DB::select('select tablename from pg_tables where schemaname = ?', [$schema])
        );
    }

    /** @return list<string> */
    private function existingRoles(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::API_ROLES), '?'));

        return array_map(
            fn ($row) => $row->rolname,
            DB::select("select rolname from pg_roles where rolname in ({$placeholders})", self::API_ROLES)
        );
    }

    private function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
};
