/**
 * URL params utilities
 * Shared helpers for building URLSearchParams from filter objects
 */

type FilterValue = string | number | boolean | string[] | null | undefined;

/**
 * Build URLSearchParams from a filter object
 *
 * Handles:
 * - Undefined/null/empty values (skipped)
 * - Arrays (joined with comma)
 * - Booleans (converted to string)
 * - Numbers (converted to string)
 *
 * @example
 * buildFilterParams({ status: 'active', page: 1, tags: ['a', 'b'] })
 * // URLSearchParams { status: 'active', page: '1', tags: 'a,b' }
 */
export function buildFilterParams(
  filters: Record<string, FilterValue>
): URLSearchParams {
  const params = new URLSearchParams();

  Object.entries(filters).forEach(([key, value]) => {
    // Skip undefined, null, empty strings
    if (value === undefined || value === null || value === '') {
      return;
    }

    // Handle arrays - join with comma
    if (Array.isArray(value)) {
      if (value.length > 0) {
        params.append(key, value.join(','));
      }
      return;
    }

    // Handle booleans and numbers
    params.append(key, String(value));
  });

  return params;
}

/**
 * Build a query string from a filter object
 * Returns empty string if no params, otherwise returns "?key=value&..."
 *
 * @example
 * buildQueryString({ page: 1, per_page: 10 })
 * // "?page=1&per_page=10"
 */
export function buildQueryString(
  filters: Record<string, FilterValue>
): string {
  const params = buildFilterParams(filters);
  const str = params.toString();
  return str ? `?${str}` : '';
}

/**
 * Build filter params for conversation list queries
 * Handles the specific filter types used in conversation hooks
 */
export function buildConversationFilterParams(
  filters: {
    status?: string | string[];
    channel_type?: string;
    telegram_chat_type?: string | string[];
    is_handover?: boolean;
    assigned_user_id?: number | string;
    tags?: string[];
    search?: string;
    from_date?: string;
    to_date?: string;
    sort_by?: string;
    sort_direction?: string;
    per_page?: number;
    page?: number;
  }
): URLSearchParams {
  const params = new URLSearchParams();

  if (filters.status) {
    params.append(
      'status',
      Array.isArray(filters.status) ? filters.status.join(',') : filters.status
    );
  }
  if (filters.channel_type) params.append('channel_type', filters.channel_type);
  if (filters.telegram_chat_type) {
    params.append(
      'telegram_chat_type',
      Array.isArray(filters.telegram_chat_type)
        ? filters.telegram_chat_type.join(',')
        : filters.telegram_chat_type
    );
  }
  if (filters.is_handover !== undefined) {
    params.append('is_handover', String(filters.is_handover));
  }
  if (filters.assigned_user_id) {
    params.append('assigned_user_id', String(filters.assigned_user_id));
  }
  if (filters.tags?.length) params.append('tags', filters.tags.join(','));
  if (filters.search) params.append('search', filters.search);
  if (filters.from_date) params.append('from_date', filters.from_date);
  if (filters.to_date) params.append('to_date', filters.to_date);
  if (filters.sort_by) params.append('sort_by', filters.sort_by);
  if (filters.sort_direction) params.append('sort_direction', filters.sort_direction);
  if (filters.per_page) params.append('per_page', String(filters.per_page));
  if (filters.page) params.append('page', String(filters.page));

  return params;
}
