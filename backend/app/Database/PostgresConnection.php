<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;

/**
 * Custom PostgreSQL Connection that properly handles boolean binding.
 *
 * PostgreSQL with native prepared statements (PDO::ATTR_EMULATE_PREPARES = false)
 * requires actual boolean values, not integers (1/0).
 *
 * This class overrides prepareBindings() to convert PHP booleans to PostgreSQL
 * native boolean format ('t'/'f' or true/false).
 */
class PostgresConnection extends BasePostgresConnection
{
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
