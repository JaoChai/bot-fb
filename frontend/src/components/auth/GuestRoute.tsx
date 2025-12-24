import { Navigate, Outlet } from 'react-router';
import { useAuthStore } from '@/stores/authStore';

export function GuestRoute() {
  const { isAuthenticated, isLoading } = useAuthStore();

  // Show loading while checking auth state
  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="text-muted-foreground">Loading...</div>
      </div>
    );
  }

  // Redirect to dashboard if already authenticated
  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  // Render child routes (login, register)
  return <Outlet />;
}
