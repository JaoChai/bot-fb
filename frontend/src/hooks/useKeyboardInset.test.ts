import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useKeyboardInset } from './useKeyboardInset';

const ORIGINAL_INNER_HEIGHT = window.innerHeight;

/** jsdom ไม่มี visualViewport — ต่อ stub ที่จำ listener ไว้ให้ยิงเองได้ */
function stubVisualViewport(height: number, offsetTop = 0) {
  const listeners: Record<string, Set<() => void>> = {
    resize: new Set(),
    scroll: new Set(),
  };
  const vv = {
    height,
    offsetTop,
    addEventListener: (type: string, fn: () => void) => listeners[type]?.add(fn),
    removeEventListener: (type: string, fn: () => void) => listeners[type]?.delete(fn),
  };
  Object.defineProperty(window, 'visualViewport', {
    value: vv,
    configurable: true,
    writable: true,
  });
  return {
    vv,
    fire: (type: 'resize' | 'scroll') => listeners[type].forEach((fn) => fn()),
    listenerCount: () => listeners.resize.size + listeners.scroll.size,
  };
}

function setInnerHeight(px: number) {
  Object.defineProperty(window, 'innerHeight', {
    value: px,
    configurable: true,
    writable: true,
  });
}

/** ทุกเคสยืนบนจอสูง 844 (iPhone) — ตั้งที่เดียวแทนซ้ำทุกเคส */
beforeEach(() => {
  setInnerHeight(844);
});

afterEach(() => {
  Object.defineProperty(window, 'visualViewport', {
    value: undefined,
    configurable: true,
    writable: true,
  });
  setInnerHeight(ORIGINAL_INNER_HEIGHT);
});

describe('useKeyboardInset', () => {
  it('คืน 0 ตอนคีย์บอร์ดปิด (visual viewport เท่ากับ layout viewport)', () => {
    stubVisualViewport(844);

    const { result } = renderHook(() => useKeyboardInset());

    expect(result.current).toBe(0);
  });

  it('คืนความสูงที่คีย์บอร์ดกินตอนเปิด', () => {
    const { fire, vv } = stubVisualViewport(844);

    const { result } = renderHook(() => useKeyboardInset());

    act(() => {
      vv.height = 508;
      fire('resize');
    });

    expect(result.current).toBe(336);
  });

  it('บวก offsetTop ด้วย เพราะ iOS เลื่อน visual viewport ไม่ได้หดอย่างเดียว', () => {
    const { fire, vv } = stubVisualViewport(844);

    const { result } = renderHook(() => useKeyboardInset());

    act(() => {
      vv.height = 508;
      vv.offsetTop = 100;
      fire('scroll');
    });

    // 844 - (508 + 100) = 236
    expect(result.current).toBe(236);
  });

  it('ไม่คืนค่าติดลบเมื่อ visual viewport ใหญ่กว่า layout viewport', () => {
    const { fire, vv } = stubVisualViewport(844);

    const { result } = renderHook(() => useKeyboardInset());

    act(() => {
      vv.height = 900;
      fire('resize');
    });

    expect(result.current).toBe(0);
  });

  it('คืน 0 เมื่อเบราว์เซอร์ไม่มี visualViewport', () => {
    const { result } = renderHook(() => useKeyboardInset());

    expect(result.current).toBe(0);
  });

  it('ถอด listener ตอน unmount', () => {
    const { listenerCount } = stubVisualViewport(844);

    const { unmount } = renderHook(() => useKeyboardInset());
    expect(listenerCount()).toBe(2);

    unmount();
    expect(listenerCount()).toBe(0);
  });
});
