import { NavLink } from 'react-router';
import { cn } from '@/lib/utils';
import { useUIStore } from '@/stores/uiStore';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAuth } from '@/hooks/useAuth';
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
} from 'lucide-react';

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
    title: 'แชท',
    href: '/chat',
    icon: MessageSquare,
  },
];

export function Sidebar() {
  const { sidebarCollapsed, toggleSidebarCollapsed } = useUIStore();
  const { user } = useAuthStore();
  const { logout, isLoggingOut } = useAuth();

  const userInitials = user?.name
    ? user.name.substring(0, 2).toUpperCase()
    : 'U';

  return (
    <aside
      className={cn(
        'hidden h-screen border-r bg-background transition-all duration-300 md:flex md:flex-col',
        sidebarCollapsed ? 'w-16' : 'w-64'
      )}
    >
      {/* Logo */}
      <div className="flex h-14 items-center justify-between border-b px-4">
        {!sidebarCollapsed && (
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-foreground text-background">
              <Sparkles className="h-4 w-4" />
            </div>
            <span className="text-sm font-semibold">BotJao</span>
          </div>
        )}
        {sidebarCollapsed && (
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-foreground text-background mx-auto">
            <Sparkles className="h-4 w-4" />
          </div>
        )}
        <Button
          variant="ghost"
          size="icon"
          onClick={toggleSidebarCollapsed}
          className={cn("h-8 w-8 text-muted-foreground hover:text-foreground", sidebarCollapsed && "hidden")}
        >
          <ChevronLeft className="h-4 w-4" />
        </Button>
      </div>

      {/* Main Navigation */}
      <nav className="flex-1 space-y-1 p-2">
        {mainNavItems.map((item) => (
          <NavLink
            key={item.href}
            to={item.href}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                isActive
                  ? 'bg-foreground text-background'
                  : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                sidebarCollapsed && 'justify-center px-2'
              )
            }
          >
            <item.icon className="h-4 w-4 shrink-0" />
            {!sidebarCollapsed && <span>{item.title}</span>}
          </NavLink>
        ))}
      </nav>

      {/* Bottom Section */}
      <div className="border-t p-2">
        {/* Settings */}
        <NavLink
          to="/settings"
          className={({ isActive }) =>
            cn(
              'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
              isActive
                ? 'bg-foreground text-background'
                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
              sidebarCollapsed && 'justify-center px-2'
            )
          }
        >
          <Settings className="h-4 w-4 shrink-0" />
          {!sidebarCollapsed && <span>ตั้งค่า</span>}
        </NavLink>

        {/* User Profile */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              className={cn(
                'mt-2 flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                'hover:bg-accent text-foreground',
                sidebarCollapsed && 'justify-center px-2'
              )}
            >
              <Avatar className="h-6 w-6">
                <AvatarFallback className="text-xs bg-muted">
                  {userInitials}
                </AvatarFallback>
              </Avatar>
              {!sidebarCollapsed && (
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
              onClick={() => logout()}
              disabled={isLoggingOut}
              className="text-destructive focus:text-destructive cursor-pointer"
            >
              <LogOut className="mr-2 h-4 w-4" />
              {isLoggingOut ? 'กำลังออก...' : 'ออกจากระบบ'}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>

        {/* Expand button when collapsed */}
        {sidebarCollapsed && (
          <Button
            variant="ghost"
            size="icon"
            onClick={toggleSidebarCollapsed}
            className="w-full h-8 mt-2 text-muted-foreground hover:text-foreground"
          >
            <ChevronLeft className="h-4 w-4 rotate-180" />
          </Button>
        )}
      </div>
    </aside>
  );
}
