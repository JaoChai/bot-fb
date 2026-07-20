import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { Header } from './Header';

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Header />
    </MemoryRouter>,
  );
}

describe('Header page title', () => {
  it('แสดง แดชบอร์ด เมื่ออยู่ /dashboard', () => {
    renderAt('/dashboard');
    expect(screen.getByText('แดชบอร์ด')).toBeInTheDocument();
  });

  it('แสดง Quick Replies เมื่ออยู่ route ซ้อน /settings/quick-replies (longest prefix ชนะ)', () => {
    renderAt('/settings/quick-replies');
    expect(screen.getByText('Quick Replies')).toBeInTheDocument();
    expect(screen.queryByText('ตั้งค่า')).not.toBeInTheDocument();
  });

  it('แสดงชื่อหน้าแม้เป็น sub-route เช่น /bots/5/settings', () => {
    renderAt('/bots/5/settings');
    expect(screen.getByText('การเชื่อมต่อ')).toBeInTheDocument();
  });
});
