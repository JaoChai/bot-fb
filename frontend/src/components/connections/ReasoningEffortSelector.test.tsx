import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { ReasoningEffortSelector } from './ReasoningEffortSelector';

describe('ReasoningEffortSelector', () => {
  it('marks the current value as checked', () => {
    render(<ReasoningEffortSelector value="high" onChange={() => {}} />);
    expect(screen.getByRole('radio', { name: /High/ })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByRole('radio', { name: /Low/ })).toHaveAttribute('aria-checked', 'false');
  });

  it('calls onChange with the clicked effort', () => {
    const onChange = vi.fn();
    render(<ReasoningEffortSelector value="medium" onChange={onChange} />);
    fireEvent.click(screen.getByRole('radio', { name: /High/ }));
    expect(onChange).toHaveBeenCalledWith('high');
  });
});
