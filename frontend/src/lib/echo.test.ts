import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

describe('echo visibility handler', () => {
  let resumedSpy: ReturnType<typeof vi.fn>;

  beforeEach(async () => {
    // Reset module so the visibility listener registers fresh
    vi.resetModules();
    resumedSpy = vi.fn();
    window.addEventListener('echo:resumed', resumedSpy as EventListener);

    // Importing the module registers the visibilitychange listener
    await import('./echo');
  });

  afterEach(() => {
    window.removeEventListener('echo:resumed', resumedSpy as EventListener);
  });

  it('dispatches echo:resumed when tab becomes visible', () => {
    Object.defineProperty(document, 'visibilityState', {
      value: 'visible',
      configurable: true,
    });
    document.dispatchEvent(new Event('visibilitychange'));

    expect(resumedSpy).toHaveBeenCalledTimes(1);
  });

  it('does NOT dispatch echo:resumed when tab becomes hidden', () => {
    Object.defineProperty(document, 'visibilityState', {
      value: 'hidden',
      configurable: true,
    });
    document.dispatchEvent(new Event('visibilitychange'));

    expect(resumedSpy).not.toHaveBeenCalled();
  });
});

describe('echo subscription_error', () => {
  let errSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.resetModules();
    errSpy = vi.fn();
    window.addEventListener('echo:subscription_error', errSpy as EventListener);
  });

  afterEach(() => {
    window.removeEventListener('echo:subscription_error', errSpy as EventListener);
  });

  it('dispatches echo:subscription_error when Pusher emits subscription_error', async () => {
    const { getEcho } = await import('./echo');
    const echo = getEcho();
    // global_emitter is Pusher-js internal; cast required because it's not in the public type surface.
    (echo.connector.pusher as unknown as {
      global_emitter: { emit: (name: string, data: unknown) => void };
    }).global_emitter.emit('pusher:subscription_error', { type: 'AuthError', error: 'invalid token' });

    expect(errSpy).toHaveBeenCalledTimes(1);
    const event = errSpy.mock.calls[0][0] as CustomEvent;
    expect(event.detail).toMatchObject({ type: 'AuthError' });
  });
});
