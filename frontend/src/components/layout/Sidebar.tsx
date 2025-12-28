import { NavLink } from 'react-router';
import { cn } from '@/lib/utils';
import { useUIStore } from '@/stores/uiStore';
import { Button } from '@/components/ui/button';
import {
  LayoutDashboard,
  Bot,
  BookOpen,
  MessageSquare,
  Settings,
  ChevronLeft,
  Sparkles,
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

const bottomNavItems = [
  {
    title: 'ตั้งค่า',
    href: '/settings',
    icon: Settings,
  },
];

export function Sidebar() {
  const { sidebarCollapsed, toggleSidebarCollapsed } = useUIStore();

  return (
    <aside
      className={cn(
        'hidden h-screen border-r bg-card transition-all duration-300 md:flex md:flex-col',
        sidebarCollapsed ? 'w-16' : 'w-64'
      )}
    >
      {/* Logo */}
      <div className="flex h-16 items-center justify-between border-b px-4">
        {!sidebarCollapsed && (
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
              <Sparkles className="h-4 w-4" />
            </div>
            <div className="flex flex-col">
              <span className="text-sm font-semibold leading-none">BotFacebook</span>
              <span className="text-[10px] text-muted-foreground">AI Chatbot Platform</span>
            </div>
          </div>
        )}
        {sidebarCollapsed && (
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground mx-auto">
            <Sparkles className="h-4 w-4" />
          </div>
        )}
        <Button
          variant="ghost"
          size="icon"
          onClick={toggleSidebarCollapsed}
          className={cn("h-8 w-8 text-muted-foreground hover:text-foreground", sidebarCollapsed && "hidden")}
        >
          <ChevronLeft
            className={cn('h-4 w-4 transition-transform', sidebarCollapsed && 'rotate-180')}
          />
        </Button>
      </div>

      {/* Main Navigation */}
      <nav className="flex-1 space-y-1 p-3">
        {mainNavItems.map((item) => (
          <NavLink
            key={item.href}
            to={item.href}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all',
                'hover:bg-accent/50',
                isActive
                  ? 'bg-primary/10 text-primary border-l-2 border-primary ml-0 pl-[10px]'
                  : 'text-muted-foreground hover:text-foreground',
                sidebarCollapsed && 'justify-center px-2 border-l-0 pl-2'
              )
            }
          >
            <item.icon className="h-5 w-5 shrink-0" />
            {!sidebarCollapsed && <span>{item.title}</span>}
          </NavLink>
        ))}
      </nav>

      {/* Bottom Navigation */}
      <div className="border-t p-3">
        {bottomNavItems.map((item) => (
          <NavLink
            key={item.href}
            to={item.href}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all',
                'hover:bg-accent/50',
                isActive
                  ? 'bg-primary/10 text-primary border-l-2 border-primary ml-0 pl-[10px]'
                  : 'text-muted-foreground hover:text-foreground',
                sidebarCollapsed && 'justify-center px-2 border-l-0 pl-2'
              )
            }
          >
            <item.icon className="h-5 w-5 shrink-0" />
            {!sidebarCollapsed && <span>{item.title}</span>}
          </NavLink>
        ))}

        {/* Expand button when collapsed */}
        {sidebarCollapsed && (
          <Button
            variant="ghost"
            size="icon"
            onClick={toggleSidebarCollapsed}
            className="w-full h-9 mt-2 text-muted-foreground hover:text-foreground"
          >
            <ChevronLeft className="h-4 w-4 rotate-180" />
          </Button>
        )}
      </div>
    </aside>
  );
}
