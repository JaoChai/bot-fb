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
     * @param  array  $bindings
     * @return array
     */
    public function prepareBindings(array $bindings)
    {
        $bindings = parent::prepareBindings($bindings);

        foreach ($bindings as $key => $value) {
            // Convert PHP boolean to PostgreSQL boolean format
            if (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return $bindings;
    }
}
