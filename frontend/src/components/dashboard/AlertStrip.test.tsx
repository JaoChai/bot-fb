import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { AlertStrip } from './AlertStrip';
import type { DashboardBotSummary } from '@/types/api';

const downBot = { name: 'Line - Adsvance', status: 'inactive' } as DashboardBotSummary;
const activeBot = { name: 'Line Support', status: 'active' } as DashboardBotSummary;

describe('AlertStrip', () => {
  it('ลิงก์ "ไปดู" ชี้ไปหน้า /bots ที่มีอยู่จริงใน router', () => {
    render(
      <MemoryRouter>
        <AlertStrip bots={[downBot]} />
      </MemoryRouter>,
    );
    expect(screen.getByRole('link', { name: /ไปดู/ })).toHaveAttribute('href', '/bots');
  });

  it('ไม่แสดงอะไรเลยเมื่อบอททุกตัวทำงานปกติ', () => {
    const { container } = render(
      <MemoryRouter>
        <AlertStrip bots={[activeBot]} />
      </MemoryRouter>,
    );
    expect(container).toBeEmptyDOMElement();
  });
});
