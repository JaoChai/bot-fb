import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import userEvent from '@testing-library/user-event';
import { AddConnectionPage } from './AddConnectionPage';

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/connections/add']}>
      <AddConnectionPage />
    </MemoryRouter>
  );
}

describe('AddConnectionPage', () => {
  it('stacks the step-2 header vertically on narrow screens and truncates a long platform name instead of wrapping it', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getByRole('button', { name: /LINE Official Account/ }));

    const changeButton = screen.getByRole('button', { name: 'เปลี่ยนแพลตฟอร์ม' });
    // The step row + the change-platform button share a flex-col-on-mobile parent.
    const stepRow = changeButton.parentElement;
    expect(stepRow).toHaveClass('flex-col');
    expect(stepRow).toHaveClass('sm:flex-row');

    const nameSpan = screen.getByText('LINE Official Account');
    expect(nameSpan).toHaveClass('truncate');
  });
});
