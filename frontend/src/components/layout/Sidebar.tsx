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
  Sun,
  Moon,
  Users,
  Zap,
  ShoppingCart,
  Star,
} from 'lucide-react';

const workspaceNavItems = [
  { title: 'แดชบอร์ด', href: '/dashboard', icon: LayoutDashboard },
  { title: 'ออเดอร์', href: '/orders', icon: ShoppingCart },
  { title: 'ลูกค้า VIP', href: '/vip-customers', icon: Star },
  { title: 'การสนทนา', href: '/chat', icon: MessageSquare },
];

const managementNavItems = [
  { title: 'การเชื่อมต่อ', href: '/bots', icon: Bot },
  { title: 'ฐานความรู้', href: '/knowledge-base', icon: BookOpen },
];

export function Sidebar() {
  const { sidebarCollapsed, toggleSidebarCollapsed, theme, setTheme } = useUIStore();
  const { user } = useAuthStore();
  const { logout, isLoggingOut } = useAuth();

  const toggleTheme = () => {
    setTheme(theme === 'dark' ? 'light' : 'dark');
  };

  const userInitials = user?.name
    ? user.name.substring(0, 2).toUpperCase()
    : 'U';

  const navLinkClass = ({ isActive }: { isActive: boolean }) =>
    cn(
      'relative flex items-center gap-3 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
      'before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-4 before:w-0.5 before:rounded-full before:bg-primary before:transition-opacity',
      isActive
        ? 'bg-accent text-foreground before:opacity-100'
        : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground before:opacity-0',
      sidebarCollapsed && 'justify-center px-2 before:hidden',
    );

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
            <div className="flex h-7 w-7 items-center justify-center rounded-md bg-primary/10 text-primary border border-primary/20">
              <Sparkles className="h-3.5 w-3.5" strokeWidth={2} />
            </div>
            <span className="text-sm font-semibold tracking-tight">BotJao</span>
          </div>
        )}
        {sidebarCollapsed && (
          <div className="flex h-7 w-7 items-center justify-center rounded-md bg-primary/10 text-primary border border-primary/20 mx-auto">
            <Sparkles className="h-3.5 w-3.5" strokeWidth={2} />
          </div>
        )}
        <Button
          variant="ghost"
          size="icon"
          onClick={toggleSidebarCollapsed}
          className={cn("h-8 w-8 text-muted-foreground hover:text-foreground", sidebarCollapsed && "hidden")}
        >
          <ChevronLeft className="h-4 w-4" strokeWidth={1.5} />
        </Button>
      </div>

      {/* Main Navigation */}
      <nav className="flex-1 space-y-4 p-2">
        <div className="space-y-1">
          {!sidebarCollapsed && (
            <p className="px-3 pb-1 pt-2 text-[11px] font-medium uppercase tracking-wider text-muted-foreground/70">
              Workspace
            </p>
          )}
          {workspaceNavItems.map((item) => (
            <NavLink
              key={item.href}
              to={item.href}
              className={navLinkClass}
            >
              <item.icon className="h-4 w-4 shrink-0" strokeWidth={1.5} />
              {!sidebarCollapsed && <span>{item.title}</span>}
            </NavLink>
          ))}
        </div>

        <div className="space-y-1">
          {!sidebarCollapsed && (
            <p className="px-3 pb-1 pt-2 text-[11px] font-medium uppercase tracking-wider text-muted-foreground/70">
              Management
            </p>
          )}
          {managementNavItems.map((item) => (
            <NavLink
              key={item.href}
              to={item.href}
              className={navLinkClass}
            >
              <item.icon className="h-4 w-4 shrink-0" strokeWidth={1.5} />
              {!sidebarCollapsed && <span>{item.title}</span>}
            </NavLink>
          ))}

          {/* Team - Owner only */}
          {user?.role === 'owner' && (
            <NavLink to="/team" className={navLinkClass}>
              <Users className="h-4 w-4 shrink-0" strokeWidth={1.5} />
              {!sidebarCollapsed && <span>จัดการทีม</span>}
            </NavLink>
          )}

          {/* Quick Replies - Owner only */}
          {user?.role === 'owner' && (
            <NavLink to="/settings/quick-replies" className={navLinkClass}>
              <Zap className="h-4 w-4 shrink-0" strokeWidth={1.5} />
              {!sidebarCollapsed && <span>Quick Replies</span>}
            </NavLink>
          )}
        </div>
      </nav>

      {/* Bottom Section */}
      <div className="border-t p-2">
        {/* Settings */}
        <NavLink
          to="/settings"
          className={navLinkClass}
        >
          <Settings className="h-4 w-4 shrink-0" strokeWidth={1.5} />
          {!sidebarCollapsed && <span>ตั้งค่า</span>}
        </NavLink>

        {/* Theme Toggle */}
        <button
          onClick={toggleTheme}
          className={cn(
            'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors w-full',
            'text-muted-foreground hover:bg-accent hover:text-foreground cursor-pointer',
            sidebarCollapsed && 'justify-center px-2'
          )}
          title={theme === 'dark' ? 'สลับเป็นโหมดสว่าง' : 'สลับเป็นโหมดมืด'}
        >
          <div className="relative h-4 w-4 shrink-0">
            <Sun className={cn(
              'absolute h-4 w-4 transition-all duration-300 ease-out',
              theme === 'dark' ? 'rotate-0 scale-100 opacity-100' : 'rotate-90 scale-0 opacity-0'
            )} />
            <Moon className={cn(
              'absolute h-4 w-4 transition-all duration-300 ease-out',
              theme === 'dark' ? '-rotate-90 scale-0 opacity-0' : 'rotate-0 scale-100 opacity-100'
            )} />
          </div>
          {!sidebarCollapsed && (
            <span>{theme === 'dark' ? 'โหมดสว่าง' : 'โหมดมืด'}</span>
          )}
        </button>

        {/* User Profile */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              className={cn(
                'mt-2 flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                'hover:bg-accent/60 text-foreground',
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
                  <ChevronsUpDown className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
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
              <LogOut className="mr-2 h-4 w-4" strokeWidth={1.5} />
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
            <ChevronLeft className="h-4 w-4 rotate-180" strokeWidth={1.5} />
          </Button>
        )}
      </div>
    </aside>
  );
}
