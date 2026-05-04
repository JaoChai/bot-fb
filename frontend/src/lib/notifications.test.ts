import { describe, it, expect, beforeEach } from 'vitest';
import { requestNotificationPermission, setUnreadBadge } from './notifications';

describe('notifications', () => {
  describe('requestNotificationPermission', () => {
    it('returns denied when Notification API not available', async () => {
      const original = window.Notification;
      // @ts-expect-error - testing missing API
      delete window.Notification;
      const result = await requestNotificationPermission();
      expect(result).toBe('denied');
      window.Notification = original;
    });

    it('returns existing permission when not default', async () => {
      Object.defineProperty(window, 'Notification', {
        value: { permission: 'granted' },
        writable: true,
        configurable: true,
      });
      const result = await requestNotificationPermission();
      expect(result).toBe('granted');
    });
  });

  describe('setUnreadBadge', () => {
    beforeEach(() => {
      document.title = 'BotJao';
    });

    it('prepends count to title', () => {
      setUnreadBadge(3);
      expect(document.title).toBe('(3) BotJao');
    });

    it('resets title when count is 0', () => {
      setUnreadBadge(3);
      setUnreadBadge(0);
      expect(document.title).toBe('BotJao');
    });
  });
});
