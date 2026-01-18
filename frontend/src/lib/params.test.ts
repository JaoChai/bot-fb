import { describe, it, expect } from 'vitest';
import { buildFilterParams, buildQueryString, buildConversationFilterParams } from './params';

describe('buildFilterParams', () => {
  it('should create empty URLSearchParams for empty object', () => {
    const params = buildFilterParams({});
    expect(params.toString()).toBe('');
  });

  it('should handle string values', () => {
    const params = buildFilterParams({ status: 'active', name: 'test' });
    expect(params.get('status')).toBe('active');
    expect(params.get('name')).toBe('test');
  });

  it('should handle number values', () => {
    const params = buildFilterParams({ page: 1, per_page: 10 });
    expect(params.get('page')).toBe('1');
    expect(params.get('per_page')).toBe('10');
  });

  it('should handle boolean values', () => {
    const params = buildFilterParams({ is_active: true, is_deleted: false });
    expect(params.get('is_active')).toBe('true');
    expect(params.get('is_deleted')).toBe('false');
  });

  it('should handle array values by joining with comma', () => {
    const params = buildFilterParams({ tags: ['a', 'b', 'c'] });
    expect(params.get('tags')).toBe('a,b,c');
  });

  it('should skip empty arrays', () => {
    const params = buildFilterParams({ tags: [] });
    expect(params.has('tags')).toBe(false);
  });

  it('should skip undefined values', () => {
    const params = buildFilterParams({ status: undefined, page: 1 });
    expect(params.has('status')).toBe(false);
    expect(params.get('page')).toBe('1');
  });

  it('should skip null values', () => {
    const params = buildFilterParams({ status: null, page: 1 });
    expect(params.has('status')).toBe(false);
    expect(params.get('page')).toBe('1');
  });

  it('should skip empty string values', () => {
    const params = buildFilterParams({ status: '', page: 1 });
    expect(params.has('status')).toBe(false);
    expect(params.get('page')).toBe('1');
  });

  it('should handle mixed values', () => {
    const params = buildFilterParams({
      status: 'active',
      page: 1,
      is_flagged: true,
      tags: ['tag1', 'tag2'],
      empty: '',
      nothing: undefined,
    });
    expect(params.get('status')).toBe('active');
    expect(params.get('page')).toBe('1');
    expect(params.get('is_flagged')).toBe('true');
    expect(params.get('tags')).toBe('tag1,tag2');
    expect(params.has('empty')).toBe(false);
    expect(params.has('nothing')).toBe(false);
  });
});

describe('buildQueryString', () => {
  it('should return empty string for empty filters', () => {
    expect(buildQueryString({})).toBe('');
  });

  it('should return query string with ? prefix', () => {
    const result = buildQueryString({ page: 1, per_page: 10 });
    expect(result).toMatch(/^\?/);
    expect(result).toContain('page=1');
    expect(result).toContain('per_page=10');
  });

  it('should return empty string when all values are falsy', () => {
    expect(buildQueryString({ status: undefined, name: null, value: '' })).toBe('');
  });
});

describe('buildConversationFilterParams', () => {
  it('should create empty URLSearchParams for empty object', () => {
    const params = buildConversationFilterParams({});
    expect(params.toString()).toBe('');
  });

  it('should handle status as string', () => {
    const params = buildConversationFilterParams({ status: 'active' });
    expect(params.get('status')).toBe('active');
  });

  it('should handle status as array', () => {
    const params = buildConversationFilterParams({ status: ['active', 'pending'] });
    expect(params.get('status')).toBe('active,pending');
  });

  it('should handle telegram_chat_type as string', () => {
    const params = buildConversationFilterParams({ telegram_chat_type: 'private' });
    expect(params.get('telegram_chat_type')).toBe('private');
  });

  it('should handle telegram_chat_type as array', () => {
    const params = buildConversationFilterParams({
      telegram_chat_type: ['private', 'group'],
    });
    expect(params.get('telegram_chat_type')).toBe('private,group');
  });

  it('should handle is_handover boolean', () => {
    const params = buildConversationFilterParams({ is_handover: true });
    expect(params.get('is_handover')).toBe('true');

    const params2 = buildConversationFilterParams({ is_handover: false });
    expect(params2.get('is_handover')).toBe('false');
  });

  it('should handle assigned_user_id as number', () => {
    const params = buildConversationFilterParams({ assigned_user_id: 123 });
    expect(params.get('assigned_user_id')).toBe('123');
  });

  it('should handle tags array', () => {
    const params = buildConversationFilterParams({ tags: ['tag1', 'tag2'] });
    expect(params.get('tags')).toBe('tag1,tag2');
  });

  it('should skip empty tags array', () => {
    const params = buildConversationFilterParams({ tags: [] });
    expect(params.has('tags')).toBe(false);
  });

  it('should handle all date and pagination fields', () => {
    const params = buildConversationFilterParams({
      search: 'test',
      from_date: '2024-01-01',
      to_date: '2024-12-31',
      sort_by: 'created_at',
      sort_direction: 'desc',
      per_page: 10,
      page: 2,
    });
    expect(params.get('search')).toBe('test');
    expect(params.get('from_date')).toBe('2024-01-01');
    expect(params.get('to_date')).toBe('2024-12-31');
    expect(params.get('sort_by')).toBe('created_at');
    expect(params.get('sort_direction')).toBe('desc');
    expect(params.get('per_page')).toBe('10');
    expect(params.get('page')).toBe('2');
  });

  it('should handle full conversation filter scenario', () => {
    const params = buildConversationFilterParams({
      status: ['active', 'pending'],
      channel_type: 'line',
      telegram_chat_type: ['private'],
      is_handover: false,
      assigned_user_id: 5,
      tags: ['vip', 'urgent'],
      search: 'customer',
      from_date: '2024-01-01',
      to_date: '2024-01-31',
      sort_by: 'last_message_at',
      sort_direction: 'desc',
      per_page: 20,
      page: 1,
    });

    expect(params.get('status')).toBe('active,pending');
    expect(params.get('channel_type')).toBe('line');
    expect(params.get('telegram_chat_type')).toBe('private');
    expect(params.get('is_handover')).toBe('false');
    expect(params.get('assigned_user_id')).toBe('5');
    expect(params.get('tags')).toBe('vip,urgent');
    expect(params.get('search')).toBe('customer');
    expect(params.get('from_date')).toBe('2024-01-01');
    expect(params.get('to_date')).toBe('2024-01-31');
    expect(params.get('sort_by')).toBe('last_message_at');
    expect(params.get('sort_direction')).toBe('desc');
    expect(params.get('per_page')).toBe('20');
    expect(params.get('page')).toBe('1');
  });
});
