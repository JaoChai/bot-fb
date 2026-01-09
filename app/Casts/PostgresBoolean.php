<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom boolean cast for PostgreSQL compatibility.
 *
 * Laravel's built-in 'boolean' cast converts true/false to 1/0 integers.
 * PostgreSQL with native prepared statements requires actual boolean values.
 *
 * This cast preserves the PHP boolean type so that our custom
 * PostgresConnection::prepareBindings() can convert it to PostgreSQL format.
 */
class PostgresBoolean implements CastsAttributes
{
    /**
     * Cast the given value when retrieving from database.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): bool
    {
        // PostgreSQL returns 't'/'f' or true/false for boolean columns
        if (is_string($value)) {
            return $value === 't' || $value === 'true' || $value === '1';
        }

        return (bool) $value;
    }

    /**
     * Prepare the given value for storage in database.
     *
     * Returns actual boolean to preserve type for PostgresConnection::prepareBindings()
     * which will convert it to PostgreSQL-compatible 'true'/'false' string.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): bool
    {
        return (bool) $value;
    }
}
