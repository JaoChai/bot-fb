import { describe, it, expect } from 'vitest';
import { shouldDehydrateQuery } from './query';

describe('shouldDehydrateQuery', () => {
  it('excludes bots from persistence', () => {
    expect(shouldDehydrateQuery({ queryKey: ['bots'] })).toBe(false);
  });

  it('excludes bot-tags from persistence', () => {
    expect(shouldDehydrateQuery({ queryKey: ['bot-tags'] })).toBe(false);
  });

  it('persists conversations', () => {
    expect(shouldDehydrateQuery({ queryKey: ['conversations-infinite', 1, {}] })).toBe(true);
  });

  it('persists messages', () => {
    expect(shouldDehydrateQuery({ queryKey: ['messages', 'list', 1, 100, {}] })).toBe(true);
  });

  it('persists auth data', () => {
    expect(shouldDehydrateQuery({ queryKey: ['auth', 'user'] })).toBe(true);
  });

  it('persists settings', () => {
    expect(shouldDehydrateQuery({ queryKey: ['settings', 'user'] })).toBe(true);
  });
});
