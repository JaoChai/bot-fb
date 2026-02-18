import { NavLink } from 'react-router';
import { cn } from '@/lib/utils';
import { useAuthStore } from '@/stores/authStore';
import { useUIStore } from '@/stores/uiStore';
import { useAuth } from '@/hooks/useAuth';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
  LayoutDashboard,
  Bot,
  BookOpen,
  MessageSquare,
  Settings,
  Sparkles,
  LogOut,
  Sun,
  Moon,
} from 'lucide-react';

interface MobileNavProps {
  onNavigate: () => void;
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
    title: 'แชท',
    href: '/chat',
    icon: MessageSquare,
  },
];

export function MobileNav({ onNavigate }: MobileNavProps) {
  const { user } = useAuthStore();
  const { theme, setTheme } = useUIStore();
  const { logout, isLoggingOut } = useAuth();

  const toggleTheme = () => {
    setTheme(theme === 'dark' ? 'light' : 'dark');
  };

  const userInitials = user?.name
    ? user.name.substring(0, 2).toUpperCase()
    : 'U';

  return (
    <div className="flex h-full flex-col bg-background">
      {/* Logo */}
      <div className="flex h-14 items-center border-b px-4 gap-3">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-foreground text-background">
          <Sparkles className="h-4 w-4" />
        </div>
        <span className="text-sm font-semibold">BotJao</span>
      </div>

      {/* Main Navigation */}
      <nav className="flex-1 space-y-1 p-2">
        {mainNavItems.map((item) => (
          <NavLink
            key={item.href}
            to={item.href}
            onClick={onNavigate}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                isActive
                  ? 'bg-foreground text-background'
                  : 'text-muted-foreground hover:bg-accent hover:text-foreground'
              )
            }
          >
            <item.icon className="h-4 w-4 shrink-0" />
            <span>{item.title}</span>
          </NavLink>
        ))}

      </nav>

      {/* Bottom Section */}
      <div className="border-t p-2">
        {/* Settings */}
        <NavLink
          to="/settings"
          onClick={onNavigate}
          className={({ isActive }) =>
            cn(
              'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
              isActive
                ? 'bg-foreground text-background'
                : 'text-muted-foreground hover:bg-accent hover:text-foreground'
            )
          }
        >
          <Settings className="h-4 w-4 shrink-0" />
          <span>ตั้งค่า</span>
        </NavLink>

        {/* Theme Toggle */}
        <button
          onClick={toggleTheme}
          className={cn(
            'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors w-full',
            'text-muted-foreground hover:bg-accent hover:text-foreground cursor-pointer'
          )}
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
          <span>{theme === 'dark' ? 'โหมดสว่าง' : 'โหมดมืด'}</span>
        </button>

        {/* User Profile */}
        <div className="mt-2 flex items-center gap-3 rounded-md px-3 py-2">
          <Avatar className="h-8 w-8">
            <AvatarFallback className="text-xs bg-muted">
              {userInitials}
            </AvatarFallback>
          </Avatar>
          <div className="flex-1 min-w-0">
            <p className="truncate text-sm font-medium">{user?.name || 'User'}</p>
            <p className="truncate text-xs text-muted-foreground">{user?.email}</p>
          </div>
        </div>

        {/* Logout Button */}
        <Button
          variant="ghost"
          className="w-full justify-start gap-3 px-3 py-2 mt-1 text-destructive hover:text-destructive hover:bg-destructive/10"
          onClick={() => logout()}
          disabled={isLoggingOut}
        >
          <LogOut className="h-4 w-4" />
          {isLoggingOut ? 'กำลังออก...' : 'ออกจากระบบ'}
        </Button>
      </div>
    </div>
  );
}
