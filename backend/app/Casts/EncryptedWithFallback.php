<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class EncryptedWithFallback implements CastsAttributes
{
    /**
     * Cast the given value (from database to PHP).
     * Handles both encrypted and plaintext values gracefully.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            // Try to decrypt - will work for properly encrypted values
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            // Value is plaintext (legacy data), return as-is
            return $value;
        }
    }

    /**
     * Prepare the given value for storage (from PHP to database).
     * Always encrypts the value.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Always encrypt when saving
        return Crypt::encryptString($value);
    }
}
