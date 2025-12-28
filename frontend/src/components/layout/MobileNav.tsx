import { NavLink } from 'react-router';
import { cn } from '@/lib/utils';
import {
  LayoutDashboard,
  Bot,
  BookOpen,
  MessageSquare,
  Settings,
  Sparkles,
} from 'lucide-react';

interface MobileNavProps {
  onNavigate: () => void;
}

const navItems = [
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
  {
    title: 'ตั้งค่า',
    href: '/settings',
    icon: Settings,
  },
];

export function MobileNav({ onNavigate }: MobileNavProps) {
  return (
    <div className="flex h-full flex-col bg-card">
      {/* Logo */}
      <div className="flex h-16 items-center border-b px-4 gap-3">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <Sparkles className="h-4 w-4" />
        </div>
        <div className="flex flex-col">
          <span className="text-sm font-semibold leading-none">BotFacebook</span>
          <span className="text-[10px] text-muted-foreground">AI Chatbot Platform</span>
        </div>
      </div>

      {/* Navigation */}
      <nav className="flex-1 space-y-1 p-3">
        {navItems.map((item) => (
          <NavLink
            key={item.href}
            to={item.href}
            onClick={onNavigate}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all',
                'hover:bg-accent/50',
                isActive
                  ? 'bg-primary/10 text-primary'
                  : 'text-muted-foreground hover:text-foreground'
              )
            }
          >
            <item.icon className="h-5 w-5 shrink-0" />
            <span>{item.title}</span>
          </NavLink>
        ))}
      </nav>
    </div>
  );
}
