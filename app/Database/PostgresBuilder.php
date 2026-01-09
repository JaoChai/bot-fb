<?php

namespace App\Database;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;

/**
 * Custom PostgreSQL Query Builder that preserves boolean types.
 *
 * Laravel's default Query\Builder::castBinding() converts booleans to integers (1/0).
 * PostgreSQL requires actual boolean values, not integers.
 *
 * This class overrides castBinding() to preserve boolean values, allowing
 * PostgresConnection::prepareBindings() to convert them to PostgreSQL format.
 */
class PostgresBuilder extends Builder
{
    /**
     * Cast the given binding value.
     *
     * Override to preserve boolean values for PostgreSQL instead of converting to int.
     *
     * @param  mixed  $value
     * @return mixed
     */
    public function castBinding($value)
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($this->grammar->getDateFormat());
        }

        // DO NOT convert booleans to integers for PostgreSQL!
        // PostgresConnection::prepareBindings() will handle conversion to 'true'/'false'
        // if (is_bool($value)) {
        //     return (int) $value;  // <-- This is what breaks PostgreSQL
        // }

        return $value;
    }
}
