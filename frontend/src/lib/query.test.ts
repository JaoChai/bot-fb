import { describe, it, expect } from 'vitest';
import { shouldDehydrateQuery, queryKeys } from './query';

// Allowlist policy (owner decision 2026-10-25, refactor plan item #2):
// only non-customer catalog roots are persisted to IndexedDB. Everything
// else — chat, orders, slips, payments, auth, settings, bot config —
// stays session-only.
describe('shouldDehydrateQuery', () => {
  it.each([
    'messages',
    'conversations-infinite',
    'conversation-stats',
    'orders',
    'slips',
    'cost-analytics',
    'dashboard',
    'search-users',
    'auth',
    'settings',
    'bots',
    'bot',
    'bot-tags',
    'bot-admins-counts',
    'knowledgeBase',
    'flows',
    'quickReplies',
    'productStocks',
  ])('does not persist private root "%s"', (root) => {
    expect(shouldDehydrateQuery({ queryKey: [root] })).toBe(false);
  });

  it('persists the models catalog (non-customer)', () => {
    expect(shouldDehydrateQuery({ queryKey: queryKeys.models.decisions() })).toBe(true);
    expect(shouldDehydrateQuery({ queryKey: ['models', 'decisions'] })).toBe(true);
  });

  it('denies by default: a root that is not allowlisted is not persisted', () => {
    expect(shouldDehydrateQuery({ queryKey: ['root-added-tomorrow'] })).toBe(false);
  });

  it('denies when the first key element is not an allowlisted string', () => {
    expect(shouldDehydrateQuery({ queryKey: [123] })).toBe(false);
    expect(shouldDehydrateQuery({ queryKey: [] })).toBe(false);
  });
});
