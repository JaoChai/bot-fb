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

export function Header() {
  const { sidebarOpen, setSidebarOpen } = useUIStore();

  return (
    <header className="sticky top-0 z-40 flex h-14 items-center border-b bg-background/80 backdrop-blur-sm supports-[backdrop-filter]:bg-background/60 px-4 md:hidden">
      {/* Mobile menu button */}
      <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
        <SheetTrigger asChild>
          <Button variant="ghost" size="icon" className="h-9 w-9">
            <Menu className="h-5 w-5" strokeWidth={1.5} />
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

      {/* Page title placeholder */}
      <div className="flex-1" />
    </header>
  );
}
