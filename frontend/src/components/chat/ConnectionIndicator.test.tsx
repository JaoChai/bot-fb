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
    expect(dot).toHaveClass('bg-green-500');
    expect(dot).not.toHaveClass('bg-red-500');
  });

  it('shows red pulsing dot when disconnected', () => {
    vi.mocked(useConnectionStore).mockImplementation((selector: (s: { isConnected: boolean }) => boolean) =>
      selector({ isConnected: false })
    );
    render(<ConnectionIndicator />);
    const dot = screen.getByTestId('connection-dot');
    expect(dot).toHaveClass('bg-red-500');
    expect(dot).toHaveClass('animate-pulse');
    expect(dot).not.toHaveClass('bg-green-500');
  });
});
