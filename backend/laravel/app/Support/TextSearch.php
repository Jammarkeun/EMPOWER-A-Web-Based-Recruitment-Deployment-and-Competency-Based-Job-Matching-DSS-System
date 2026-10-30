<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Matching what somebody typed into a search box.
 *
 * LIKE reads % and _ as wildcards, so typing "50%" also found "500 Foods",
 * "jo_an" found "joxan", and a lone "%" returned every record. Every search
 * box goes through here, so the characters typed are compared as themselves.
 *
 * The escape character is declared with ESCAPE on every condition rather
 * than left to the backslash default: MySQL and PostgreSQL share that
 * default but SQLite, which the test suite runs on, has none, and a
 * backslash then has to stay an ordinary character for the archive search
 * (see ArchiveController).
 *
 * ILIKE on PostgreSQL, whose LIKE is case-sensitive, so searching "santos"
 * still finds "Santos". MySQL's text columns and SQLite's LIKE already ignore
 * case.
 */
final class TextSearch
{
    public const ESCAPE = '!';

    /** "%text%", with any wildcard in the text made literal. */
    public static function contains(string $text): string
    {
        return '%'.strtr($text, [
            self::ESCAPE => self::ESCAPE.self::ESCAPE,
            '%' => self::ESCAPE.'%',
            '_' => self::ESCAPE.'_',
        ]).'%';
    }

    /** The comparison for the connection, ending in the escape clause. */
    public static function operator(Builder $query): string
    {
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $like.' ? escape \''.self::ESCAPE.'\'';
    }

    /**
     * `$query->whereContains('column', $text)` and `orWhereContains(...)`, on
     * query and Eloquent builders alike. The column may be a JSON path
     * (`new_values_json->email`); the grammar writes it for the connection.
     */
    public static function register(): void
    {
        Builder::macro('whereContains', function (string $column, string $text, string $boolean = 'and') {
            /** @var Builder $this */
            return $this->whereRaw(
                $this->getGrammar()->wrap($column).' '.TextSearch::operator($this),
                [TextSearch::contains($text)],
                $boolean,
            );
        });

        Builder::macro('orWhereContains', function (string $column, string $text) {
            /** @var Builder $this */
            return $this->whereContains($column, $text, 'or');
        });
    }
}
