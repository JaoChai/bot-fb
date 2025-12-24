import { Outlet } from 'react-router';

export function AuthLayout() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-muted/50 p-4">
      <div className="mb-8 text-center">
        <h1 className="text-3xl font-bold">BotFacebook</h1>
        <p className="text-muted-foreground">AI-powered chatbot platform</p>
      </div>
      <Outlet />
    </div>
  );
}
