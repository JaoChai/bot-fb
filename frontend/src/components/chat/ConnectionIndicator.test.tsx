import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ConnectionIndicator } from './ConnectionIndicator';

vi.mock('@/stores/connectionStore', () => ({
  useConnectionStore: vi.fn(),
}));

import { useConnectionStore } from '@/stores/connectionStore';

describe('ConnectionIndicator', () => {
  it('shows green dot when connected', () => {
    vi.mocked(useConnectionStore).mockImplementation((selector: (s: { isConnected: boolean }) => boolean) =>
      selector({ isConnected: true })
    );
    render(<ConnectionIndicator />);
    const dot = screen.getByTestId('connection-dot');
    expect(dot.className).toContain('bg-green');
  });

  it('shows red dot when disconnected', () => {
    vi.mocked(useConnectionStore).mockImplementation((selector: (s: { isConnected: boolean }) => boolean) =>
      selector({ isConnected: false })
    );
    render(<ConnectionIndicator />);
    const dot = screen.getByTestId('connection-dot');
    expect(dot.className).toContain('bg-red');
  });
});
