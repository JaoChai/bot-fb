import { useUIStore } from '@/stores/uiStore';
import { Button } from '@/components/ui/button';
import {
  Sheet,
  SheetContent,
  SheetTrigger,
} from '@/components/ui/sheet';
import { MobileNav } from './MobileNav';
import { Menu } from 'lucide-react';

export function Header() {
  const { sidebarOpen, setSidebarOpen } = useUIStore();

  return (
    <header className="flex h-14 items-center border-b bg-background px-4 md:hidden">
      {/* Mobile menu button */}
      <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
        <SheetTrigger asChild>
          <Button variant="ghost" size="icon" className="h-9 w-9">
            <Menu className="h-5 w-5" />
            <span className="sr-only">เปิดเมนู</span>
          </Button>
        </SheetTrigger>
        <SheetContent side="left" className="w-72 p-0">
          <MobileNav onNavigate={() => setSidebarOpen(false)} />
        </SheetContent>
      </Sheet>

      {/* Page title placeholder */}
      <div className="flex-1" />
    </header>
  );
}
