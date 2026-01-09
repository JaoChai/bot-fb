import { PropsWithChildren, useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { cn } from '@/Lib/utils';
import { Button } from '@/Components/ui/button';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import {
  Sheet,
  SheetContent,
  SheetTrigger,
  SheetTitle,
  SheetDescription,
} from '@/Components/ui/sheet';
import {
  LayoutDashboard,
  Bot,
  BookOpen,
  MessageSquare,
  Settings,
  ChevronLeft,
  Sparkles,
  LogOut,
  ChevronsUpDown,
  Target,
  Sun,
  Moon,
  Users,
  Zap,
  Menu,
} from 'lucide-react';
import type { SharedProps } from '@/types';

interface Props extends PropsWithChildren {
  header?: string;
}

const mainNavItems = [
  {
    title: 'แดชบอร์ด',
    href: '/dashboard',
    icon: LayoutDashboard,
  },
  {
    title: 'การเชื่อมต่อ',
    href: '/bots',
    icon: Bot,
  },
  {
    title: 'ฐานความรู้',
    href: '/knowledge-base',
    icon: BookOpen,
  },
  {
    title: 'การสนทนา',
    href: '/chat',
    icon: MessageSquare,
  },
  {
    title: 'ประเมินบอท',
    href: '/evaluations',
    icon: Target,
  },
];

export default function AuthenticatedLayout({ children, header }: Props) {
  const { auth } = usePage<SharedProps>().props;
  const user = auth.user;

  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [theme, setTheme] = useState<'light' | 'dark'>(() => {
    if (typeof window !== 'undefined') {
      return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
    }
    return 'light';
  });

  const toggleTheme = () => {
    const newTheme = theme === 'dark' ? 'light' : 'dark';
    setTheme(newTheme);
    document.documentElement.classList.toggle('dark', newTheme === 'dark');
    localStorage.setItem('theme', newTheme);
  };

  const handleLogout = () => {
    router.post('/logout');
  };

  const userInitials = user?.name ? user.name.substring(0, 2).toUpperCase() : 'U';

  const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

  const NavLink = ({
    href,
    icon: Icon,
    title,
    collapsed = false,
  }: {
    href: string;
    icon: React.ElementType;
    title: string;
    collapsed?: boolean;
  }) => {
    const isActive = currentPath === href || currentPath.startsWith(href + '/');
    return (
      <Link
        href={href}
        className={cn(
          'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
          isActive
            ? 'bg-foreground text-background'
            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
          collapsed && 'justify-center px-2'
        )}
      >
        <Icon className="h-4 w-4 shrink-0" />
        {!collapsed && <span>{title}</span>}
      </Link>
    );
  };

  // Sidebar Content (shared between desktop and mobile)
  const SidebarContent = ({ collapsed = false, onNavigate }: { collapsed?: boolean; onNavigate?: () => void }) => (
    <>
      {/* Logo */}
      <div className="flex h-14 items-center justify-between border-b px-4">
        {!collapsed && (
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-foreground text-background">
              <Sparkles className="h-4 w-4" />
            </div>
            <span className="text-sm font-semibold">BotJao</span>
          </div>
        )}
        {collapsed && (
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-foreground text-background mx-auto">
            <Sparkles className="h-4 w-4" />
          </div>
        )}
        <Button
          variant="ghost"
          size="icon"
          onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
          className={cn('h-8 w-8 text-muted-foreground hover:text-foreground', collapsed && 'hidden')}
        >
          <ChevronLeft className="h-4 w-4" />
        </Button>
      </div>

      {/* Main Navigation */}
      <nav className="flex-1 space-y-1 p-2">
        {mainNavItems.map((item) => (
          <div key={item.href} onClick={onNavigate}>
            <NavLink {...item} collapsed={collapsed} />
          </div>
        ))}

        {/* Team - Owner only */}
        {user?.role === 'owner' && (
          <div onClick={onNavigate}>
            <NavLink href="/team" icon={Users} title="จัดการทีม" collapsed={collapsed} />
          </div>
        )}

        {/* Quick Replies - Owner only */}
        {user?.role === 'owner' && (
          <div onClick={onNavigate}>
            <NavLink href="/settings/quick-replies" icon={Zap} title="Quick Replies" collapsed={collapsed} />
          </div>
        )}
      </nav>

      {/* Bottom Section */}
      <div className="border-t p-2">
        {/* Settings */}
        <div onClick={onNavigate}>
          <NavLink href="/settings" icon={Settings} title="ตั้งค่า" collapsed={collapsed} />
        </div>

        {/* Theme Toggle */}
        <button
          onClick={toggleTheme}
          className={cn(
            'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors w-full',
            'text-muted-foreground hover:bg-accent hover:text-foreground cursor-pointer',
            collapsed && 'justify-center px-2'
          )}
          title={theme === 'dark' ? 'สลับเป็นโหมดสว่าง' : 'สลับเป็นโหมดมืด'}
        >
          <div className="relative h-4 w-4 shrink-0">
            <Sun
              className={cn(
                'absolute h-4 w-4 transition-all duration-300 ease-out',
                theme === 'dark' ? 'rotate-0 scale-100 opacity-100' : 'rotate-90 scale-0 opacity-0'
              )}
            />
            <Moon
              className={cn(
                'absolute h-4 w-4 transition-all duration-300 ease-out',
                theme === 'dark' ? '-rotate-90 scale-0 opacity-0' : 'rotate-0 scale-100 opacity-100'
              )}
            />
          </div>
          {!collapsed && <span>{theme === 'dark' ? 'โหมดสว่าง' : 'โหมดมืด'}</span>}
        </button>

        {/* User Profile */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              className={cn(
                'mt-2 flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                'hover:bg-accent text-foreground',
                collapsed && 'justify-center px-2'
              )}
            >
              <Avatar className="h-6 w-6">
                <AvatarFallback className="text-xs bg-muted">{userInitials}</AvatarFallback>
              </Avatar>
              {!collapsed && (
                <>
                  <div className="flex-1 text-left">
                    <p className="truncate text-sm font-medium">{user?.name || 'User'}</p>
                    <p className="truncate text-xs text-muted-foreground">{user?.email}</p>
                  </div>
                  <ChevronsUpDown className="h-4 w-4 text-muted-foreground" />
                </>
              )}
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            <div className="px-2 py-1.5">
              <p className="text-sm font-medium">{user?.name}</p>
              <p className="text-xs text-muted-foreground">{user?.email}</p>
            </div>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              onClick={handleLogout}
              className="text-destructive focus:text-destructive cursor-pointer"
            >
              <LogOut className="mr-2 h-4 w-4" />
              ออกจากระบบ
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>

        {/* Expand button when collapsed */}
        {collapsed && (
          <Button
            variant="ghost"
            size="icon"
            onClick={() => setSidebarCollapsed(false)}
            className="w-full h-8 mt-2 text-muted-foreground hover:text-foreground"
          >
            <ChevronLeft className="h-4 w-4 rotate-180" />
          </Button>
        )}
      </div>
    </>
  );

  return (
    <div className="flex h-screen bg-background">
      {/* Desktop Sidebar */}
      <aside
        className={cn(
          'hidden h-screen border-r bg-background transition-all duration-300 md:flex md:flex-col',
          sidebarCollapsed ? 'w-16' : 'w-64'
        )}
      >
        <SidebarContent collapsed={sidebarCollapsed} />
      </aside>

      {/* Main content area */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Mobile Header */}
        <header className="sticky top-0 z-40 flex h-14 items-center border-b bg-background px-4 md:hidden">
          <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
            <SheetTrigger asChild>
              <Button variant="ghost" size="icon" className="h-9 w-9">
                <Menu className="h-5 w-5" />
                <span className="sr-only">เปิดเมนู</span>
              </Button>
            </SheetTrigger>
            <SheetContent side="left" className="w-72 p-0">
              <SheetTitle className="sr-only">Navigation Menu</SheetTitle>
              <SheetDescription className="sr-only">Navigate to different sections</SheetDescription>
              <div className="flex h-full flex-col">
                <SidebarContent onNavigate={() => setSidebarOpen(false)} />
              </div>
            </SheetContent>
          </Sheet>

          {/* Page title */}
          {header && <h1 className="ml-4 text-lg font-semibold">{header}</h1>}

          <div className="flex-1" />
        </header>

        {/* Page Content */}
        <main className="flex-1 overflow-auto p-4 md:p-6 pt-14 md:pt-6">{children}</main>
      </div>
    </div>
  );
}
