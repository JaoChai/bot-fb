<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;
use Illuminate\Database\Query\Builder;

/**
 * Custom PostgreSQL Connection that properly handles boolean binding.
 *
 * PostgreSQL requires actual boolean values, not integers (1/0).
 *
 * This class:
 * 1. Uses custom PostgresBuilder that preserves boolean types (prevents int conversion)
 * 2. Overrides prepareBindings() to convert PHP booleans to PostgreSQL format ('true'/'false')
 */
class PostgresConnection extends BasePostgresConnection
{
    /**
     * Get a new query builder instance.
     *
     * Returns our custom PostgresBuilder that preserves boolean types
     * instead of converting them to integers.
     *
     * @return \App\Database\PostgresBuilder
     */
    public function query(): Builder
    {
        return new PostgresBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }

    /**
     * Prepare the query bindings for execution.
     *
     * Converts boolean values to PostgreSQL-compatible format.
     *
     * CRITICAL: Must convert booleans BEFORE calling parent::prepareBindings()
     * because the parent method converts booleans to integers (0/1) which
     * PostgreSQL rejects for boolean columns.
     *
     * @param  array  $bindings
     * @return array
     */
    public function prepareBindings(array $bindings)
    {
        // FIRST: Convert PHP booleans to PostgreSQL format BEFORE parent processes them
        // Parent::prepareBindings() converts bool to int, which breaks PostgreSQL!
        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        // Now call parent for other type conversions (DateTime, etc.)
        // Booleans are already strings so parent won't touch them
        return parent::prepareBindings($bindings);
    }
}
