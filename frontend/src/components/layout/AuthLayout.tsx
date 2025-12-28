import { Outlet } from 'react-router';
import { Sparkles } from 'lucide-react';

export function AuthLayout() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-indigo-50 via-white to-slate-50 dark:from-slate-900 dark:via-slate-900 dark:to-indigo-950 p-4">
      {/* Decorative background */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute -top-40 -right-40 w-80 h-80 bg-primary/5 rounded-full blur-3xl" />
        <div className="absolute -bottom-40 -left-40 w-80 h-80 bg-primary/5 rounded-full blur-3xl" />
      </div>

      <div className="relative mb-8 text-center">
        <div className="flex items-center justify-center gap-3 mb-2">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-lg">
            <Sparkles className="h-6 w-6" />
          </div>
        </div>
        <h1 className="text-3xl font-bold text-foreground">BotFacebook</h1>
        <p className="text-muted-foreground">AI Chatbot Platform</p>
      </div>
      <div className="relative w-full max-w-md">
        <Outlet />
      </div>
    </div>
  );
}
