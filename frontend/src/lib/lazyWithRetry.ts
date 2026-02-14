import { lazy } from "react"
import type { ComponentType } from "react"

const CHUNK_RELOAD_KEY = "chunk_reload_attempted"

/**
 * Check if error is a chunk loading error (stale deployment)
 */
function isChunkLoadError(error: unknown): boolean {
  if (error instanceof Error) {
    const message = error.message.toLowerCase()
    return (
      message.includes("failed to fetch dynamically imported module") ||
      message.includes("loading chunk") ||
      message.includes("loading css chunk") ||
      message.includes("unable to preload")
    )
  }
  return false
}

/**
 * Auto-reload page once when chunk loading fails
 * This handles stale deployments gracefully
 */
function handleChunkError(): void {
  const hasReloaded = sessionStorage.getItem(CHUNK_RELOAD_KEY)

  if (!hasReloaded) {
    sessionStorage.setItem(CHUNK_RELOAD_KEY, "true")
    window.location.reload()
  }
}

/**
 * Check if we've already attempted a reload for chunk errors
 */
export function hasAttemptedChunkReload(): boolean {
  return sessionStorage.getItem(CHUNK_RELOAD_KEY) === "true"
}

/**
 * Named export variant for components that use named exports
 * Includes retry logic and auto-reload on chunk errors
 *
 * When a new deployment happens, old chunk files are replaced.
 * Users with cached routing may try to load chunks that no longer exist.
 * This wrapper:
 * 1. Retries the import once after a short delay
 * 2. If retry fails and it's a chunk error, auto-reloads the page (once)
 * 3. If still failing, throws the error for ErrorBoundary to catch
 */
export function lazyWithRetryNamed<
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  T extends Record<string, ComponentType<any>>,
  K extends keyof T
>(
  importFn: () => Promise<T>,
  exportName: K,
  retries = 1,
  retryDelay = 1000
) {
  return lazy(async () => {
    try {
      const module = await importFn()
      return { default: module[exportName] }
    } catch (error) {
      // Retry logic
      for (let i = 0; i < retries; i++) {
        await new Promise(resolve => setTimeout(resolve, retryDelay))
        try {
          const module = await importFn()
          return { default: module[exportName] }
        } catch {
          // Continue to next retry
        }
      }

      // All retries failed - check if it's a chunk error
      if (isChunkLoadError(error)) {
        handleChunkError()
      }

      throw error
    }
  })
}
