/**
 * useCountdown - Reusable countdown timer hook
 *
 * Extracted from BotControl.tsx for reusability.
 * Features:
 * - Pauses when tab is hidden (performance optimization)
 * - Returns formatted time string
 * - Handles null/undefined targetTime gracefully
 *
 * @example
 * const { remainingSeconds, formatted, isExpired } = useCountdown({
 *   targetTime: conversation.bot_auto_enable_at,
 *   enabled: conversation.is_handover,
 * });
 */
import { useState, useEffect } from 'react';

export interface UseCountdownOptions {
  /** Target time in ISO string format */
  targetTime: string | null | undefined;
  /** Whether the countdown should be active */
  enabled: boolean;
  /** Pause countdown when tab is hidden (default: true) */
  pauseOnHidden?: boolean;
}

export interface CountdownResult {
  /** Remaining seconds (null if not counting) */
  remainingSeconds: number | null;
  /** Formatted time string (e.g., "2:30") */
  formatted: string | null;
  /** Whether the countdown has expired */
  isExpired: boolean;
  /** Whether the countdown is currently active */
  isActive: boolean;
}

/**
 * Format seconds to MM:SS string
 */
function formatTime(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

/**
 * Hook to manage countdown timer
 *
 * @param options - Countdown options
 * @returns Countdown state
 */
export function useCountdown({
  targetTime,
  enabled,
  pauseOnHidden = true,
}: UseCountdownOptions): CountdownResult {
  const [remainingSeconds, setRemainingSeconds] = useState<number | null>(null);

  useEffect(() => {
    // Don't run countdown if not enabled or no target time
    if (!enabled || !targetTime) {
      setRemainingSeconds(null);
      return;
    }

    const targetTimeMs = new Date(targetTime).getTime();
    let intervalId: ReturnType<typeof setInterval> | null = null;

    const updateCountdown = (): number => {
      const now = Date.now();
      const diff = Math.max(0, Math.floor((targetTimeMs - now) / 1000));
      setRemainingSeconds(diff);
      return diff;
    };

    const startInterval = () => {
      if (intervalId) return;
      const diff = updateCountdown();
      if (diff <= 0) return;

      intervalId = setInterval(() => {
        const remaining = updateCountdown();
        if (remaining <= 0 && intervalId) {
          clearInterval(intervalId);
          intervalId = null;
        }
      }, 1000);
    };

    const stopInterval = () => {
      if (intervalId) {
        clearInterval(intervalId);
        intervalId = null;
      }
    };

    const handleVisibilityChange = () => {
      if (!pauseOnHidden) return;

      if (document.hidden) {
        stopInterval();
      } else {
        startInterval();
      }
    };

    // Start if tab is visible
    if (!document.hidden) {
      startInterval();
    }

    // Listen for visibility changes
    if (pauseOnHidden) {
      document.addEventListener('visibilitychange', handleVisibilityChange);
    }

    return () => {
      stopInterval();
      if (pauseOnHidden) {
        document.removeEventListener('visibilitychange', handleVisibilityChange);
      }
    };
  }, [enabled, targetTime, pauseOnHidden]);

  // Derive state from remainingSeconds
  const isExpired = remainingSeconds !== null && remainingSeconds <= 0;
  const isActive = remainingSeconds !== null && remainingSeconds > 0;
  const formatted = isActive ? formatTime(remainingSeconds) : null;

  return {
    remainingSeconds,
    formatted,
    isExpired,
    isActive,
  };
}
