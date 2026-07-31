import { describe, it, expect, afterEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import { useRef } from 'react';
import { useDesktopAutoFocus } from './useDesktopAutoFocus';

/** jsdom ไม่มี matchMedia — stub ให้ตอบตามความกว้างที่กำหนด */
function stubMatchMedia(isDesktop: boolean) {
  Object.defineProperty(window, 'matchMedia', {
    value: vi.fn().mockReturnValue({
      matches: isDesktop,
      media: '(min-width: 768px)',
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    }),
    configurable: true,
    writable: true,
  });
}

function Probe() {
  const ref = useRef<HTMLTextAreaElement>(null);
  useDesktopAutoFocus(ref);
  return <textarea ref={ref} aria-label="probe" />;
}

afterEach(() => {
  Object.defineProperty(window, 'matchMedia', {
    value: undefined,
    configurable: true,
    writable: true,
  });
});

describe('useDesktopAutoFocus', () => {
  it('focus ให้บน desktop', () => {
    stubMatchMedia(true);
    const { getByLabelText } = render(<Probe />);

    expect(document.activeElement).toBe(getByLabelText('probe'));
  });

  it('ไม่ focus บนมือถือ เพื่อไม่ให้คีย์บอร์ดเด้งบังข้อความ', () => {
    stubMatchMedia(false);
    const { getByLabelText } = render(<Probe />);

    expect(document.activeElement).not.toBe(getByLabelText('probe'));
  });
});
