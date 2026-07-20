import { useLocation } from 'react-router';
import { useUIStore } from '@/stores/uiStore';
import { Button } from '@/components/ui/button';
import {
  Sheet,
  SheetContent,
  SheetTrigger,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet';
import * as VisuallyHidden from '@radix-ui/react-visually-hidden';
import { MobileNav } from './MobileNav';
import { Menu } from 'lucide-react';

const PAGE_TITLES: Record<string, string> = {
  '/dashboard': 'แดชบอร์ด',
  '/orders': 'ออเดอร์',
  '/vip-customers': 'ลูกค้า VIP',
  '/slips': 'รายการสลิป',
  '/bots': 'การเชื่อมต่อ',
  '/connections': 'การเชื่อมต่อ',
  '/knowledge-base': 'ฐานความรู้',
  '/chat': 'แชท',
  '/settings/quick-replies': 'Quick Replies',
  '/settings': 'ตั้งค่า',
  '/telegram': 'Telegram',
  '/team': 'จัดการทีม',
};

function pageTitle(pathname: string): string {
  const match = Object.keys(PAGE_TITLES)
    .sort((a, b) => b.length - a.length)
    .find((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
  return match ? PAGE_TITLES[match] : '';
}

export function Header() {
  const { sidebarOpen, setSidebarOpen } = useUIStore();
  const { pathname } = useLocation();

  return (
    <header className="sticky top-0 z-40 flex h-14 items-center border-b bg-background/80 backdrop-blur-sm supports-[backdrop-filter]:bg-background/60 px-4 md:hidden">
      {/* Mobile menu button */}
      <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
        <SheetTrigger asChild>
          <Button variant="ghost" size="icon" className="size-9">
            <Menu className="size-5" strokeWidth={1.5} />
            <span className="sr-only">เปิดเมนู</span>
          </Button>
        </SheetTrigger>
        <SheetContent side="left" className="w-72 p-0">
          <VisuallyHidden.Root>
            <SheetTitle>Navigation Menu</SheetTitle>
            <SheetDescription>Navigate to different sections of the application</SheetDescription>
          </VisuallyHidden.Root>
          <MobileNav onNavigate={() => setSidebarOpen(false)} />
        </SheetContent>
      </Sheet>

      {/* Current page title */}
      <p className="ml-2 flex-1 truncate text-sm font-semibold">{pageTitle(pathname)}</p>
    </header>
  );
}
