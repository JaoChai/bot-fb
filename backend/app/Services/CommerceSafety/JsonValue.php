<?php

namespace App\Services\CommerceSafety;

final class JsonValue
{
    /** Compare decoded JSON exactly while ignoring object member order. */
    public static function equals(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }

        if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
            return false;
        }

        if (array_is_list($left)) {
            foreach ($left as $index => $value) {
                if (! self::equals($value, $right[$index])) {
                    return false;
                }
            }

            return true;
        }

        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || ! self::equals($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }
}
