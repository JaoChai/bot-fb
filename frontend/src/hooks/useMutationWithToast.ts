import { useMutation, useQueryClient, type UseMutationOptions, type UseMutationResult } from '@tanstack/react-query';
import { toast } from 'sonner';

/**
 * Options for useMutationWithToast hook
 */
export interface MutationWithToastOptions<TData, TError, TVariables, TContext = unknown>
  extends Omit<UseMutationOptions<TData, TError, TVariables, TContext>, 'onSuccess' | 'onError'> {
  /**
   * Success message to show. Can be a string or a function that receives the data.
   * If not provided, no success toast will be shown.
   */
  successMessage?: string | ((data: TData, variables: TVariables) => string);

  /**
   * Error message to show. Can be a string or a function that receives the error.
   * If not provided, a default error message will be shown.
   */
  errorMessage?: string | ((error: TError, variables: TVariables) => string);

  /**
   * Query keys to invalidate on success.
   * Each key is an array that will be passed to queryClient.invalidateQueries.
   */
  invalidateKeys?: readonly (readonly unknown[])[];

  /**
   * Additional callback to run on success (after toast and invalidation)
   */
  onSuccess?: (data: TData, variables: TVariables, context: TContext | undefined) => void;

  /**
   * Additional callback to run on error (after toast)
   */
  onError?: (error: TError, variables: TVariables, context: TContext | undefined) => void;

  /**
   * Whether to show success toast. Default: true if successMessage is provided
   */
  showSuccessToast?: boolean;

  /**
   * Whether to show error toast. Default: true
   */
  showErrorToast?: boolean;
}

/**
 * Extract error message from various error types
 */
function getErrorMessage(error: unknown): string {
  if (error instanceof Error) {
    return error.message;
  }
  if (typeof error === 'object' && error !== null) {
    // Handle API error responses
    const errorObj = error as Record<string, unknown>;
    if (typeof errorObj.message === 'string') {
      return errorObj.message;
    }
    if (typeof errorObj.error === 'string') {
      return errorObj.error;
    }
    // Handle axios error structure
    if (errorObj.response && typeof errorObj.response === 'object') {
      const response = errorObj.response as Record<string, unknown>;
      if (response.data && typeof response.data === 'object') {
        const data = response.data as Record<string, unknown>;
        if (typeof data.message === 'string') {
          return data.message;
        }
        if (typeof data.error === 'string') {
          return data.error;
        }
      }
    }
  }
  if (typeof error === 'string') {
    return error;
  }
  return 'An unexpected error occurred';
}

/**
 * A wrapper around useMutation that provides automatic toast notifications
 * and query invalidation.
 *
 * @example
 * ```tsx
 * const createBot = useMutationWithToast({
 *   mutationFn: (data) => api.createBot(data),
 *   successMessage: 'Bot created successfully!',
 *   errorMessage: (error) => `Failed to create bot: ${error.message}`,
 *   invalidateKeys: [queryKeys.bots.lists()],
 * });
 *
 * // Or with dynamic success message
 * const updateBot = useMutationWithToast({
 *   mutationFn: (data) => api.updateBot(data),
 *   successMessage: (bot) => `${bot.name} updated successfully!`,
 *   invalidateKeys: [queryKeys.bots.lists(), queryKeys.bots.detail(botId)],
 * });
 * ```
 */
export function useMutationWithToast<TData = unknown, TError = Error, TVariables = void, TContext = unknown>(
  options: MutationWithToastOptions<TData, TError, TVariables, TContext>
): UseMutationResult<TData, TError, TVariables, TContext> {
  const queryClient = useQueryClient();

  const {
    successMessage,
    errorMessage,
    invalidateKeys,
    onSuccess: customOnSuccess,
    onError: customOnError,
    showSuccessToast = !!successMessage,
    showErrorToast = true,
    ...mutationOptions
  } = options;

  return useMutation({
    ...mutationOptions,
    onSuccess: (data, variables, context) => {
      // Show success toast
      if (showSuccessToast && successMessage) {
        const message = typeof successMessage === 'function'
          ? successMessage(data, variables)
          : successMessage;
        toast.success(message);
      }

      // Invalidate query keys
      if (invalidateKeys && invalidateKeys.length > 0) {
        invalidateKeys.forEach((key) => {
          queryClient.invalidateQueries({ queryKey: [...key] });
        });
      }

      // Call custom onSuccess
      customOnSuccess?.(data, variables, context);
    },
    onError: (error, variables, context) => {
      // Show error toast
      if (showErrorToast) {
        const message = errorMessage
          ? (typeof errorMessage === 'function'
              ? errorMessage(error, variables)
              : errorMessage)
          : getErrorMessage(error);
        toast.error(message);
      }

      // Call custom onError
      customOnError?.(error, variables, context);
    },
  });
}

/**
 * Simplified version for mutations that only need basic toast behavior
 */
export function useSimpleMutation<TData = unknown, TVariables = void>(
  mutationFn: (variables: TVariables) => Promise<TData>,
  options?: {
    successMessage?: string;
    errorMessage?: string;
    invalidateKeys?: readonly (readonly unknown[])[];
  }
) {
  return useMutationWithToast({
    mutationFn,
    ...options,
  });
}
