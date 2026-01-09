import { PropsWithChildren } from 'react';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }: PropsWithChildren) {
  return (
    <div className="min-h-screen flex flex-col bg-background">
      {/* Header */}
      <header className="border-b bg-card">
        <div className="container mx-auto px-4 h-16 flex items-center justify-between">
          <Link href="/" className="text-xl font-bold text-foreground">
            BotFacebook
          </Link>
          <nav className="flex items-center gap-4">
            <Link
              href="/login"
              className="text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
              Login
            </Link>
            <Link
              href="/register"
              className="text-sm bg-primary text-primary-foreground px-4 py-2 rounded-md hover:bg-primary/90 transition-colors"
            >
              Register
            </Link>
          </nav>
        </div>
      </header>

      {/* Main Content */}
      <main className="flex-1 flex items-center justify-center p-4">
        <div className="w-full max-w-md">
          {children}
        </div>
      </main>

      {/* Footer */}
      <footer className="border-t bg-card py-4">
        <div className="container mx-auto px-4 text-center text-sm text-muted-foreground">
          &copy; {new Date().getFullYear()} BotFacebook. All rights reserved.
        </div>
      </footer>
    </div>
  );
}
