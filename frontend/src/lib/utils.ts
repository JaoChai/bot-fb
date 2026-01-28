import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Safely coerce a value to an array with runtime validation.
 * Returns the value if it's already an array, otherwise returns the fallback.
 *
 * Use this instead of `value as T[] || []` which fails when value is
 * a truthy non-array (e.g., a string or object).
 *
 * @example
 * toSafeArray(data.checks)        // Returns data.checks if array, else []
 * toSafeArray("string")           // Returns []
 * toSafeArray(undefined)          // Returns []
 * toSafeArray(null, ['default'])  // Returns ['default']
 */
export function toSafeArray<T>(value: unknown, fallback: T[] = []): T[] {
  return Array.isArray(value) ? value : fallback;
}
