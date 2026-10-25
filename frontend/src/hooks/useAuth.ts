import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router';
import { apiPost } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import { clearSessionQueries } from '@/lib/sessionCache';
import { useAuthStore } from '@/stores/authStore';
import type {
  LoginCredentials,
  RegisterCredentials,
  AuthResponse,
} from '@/types/api';

// Login mutation
function useLogin() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const { login } = useAuthStore();

  return useMutation({
    mutationFn: async (credentials: LoginCredentials) => {
      const response = await apiPost<AuthResponse>('/auth/login', credentials);
      return response;
    },
    onSuccess: async (data) => {
      // Account replacement: drop any cached data left by a previous user
      // (e.g. after a forced 401 logout) before this user's data lands.
      await clearSessionQueries();
      login(data.user, data.token);
      queryClient.setQueryData(queryKeys.auth.user(), data.user);
      navigate('/dashboard');
    },
  });
}

// Register mutation
function useRegister() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const { login } = useAuthStore();

  return useMutation({
    mutationFn: async (credentials: RegisterCredentials) => {
      const response = await apiPost<AuthResponse>('/auth/register', credentials);
      return response;
    },
    onSuccess: (data) => {
      login(data.user, data.token);
      queryClient.setQueryData(queryKeys.auth.user(), data.user);
      navigate('/dashboard');
    },
  });
}

// Logout mutation
function useLogout() {
  const navigate = useNavigate();
  const { logout } = useAuthStore();

  return useMutation({
    mutationFn: async () => {
      await apiPost('/auth/logout');
    },
    onSettled: async () => {
      // Always logout locally, even if API call fails
      logout();
      // Drop cached queries in memory AND the persisted IndexedDB copy.
      await clearSessionQueries();
      navigate('/login');
    },
  });
}

// Convenience hook - returns auth state and actions
export function useAuth() {
  const user = useAuthStore((state) => state.user);
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  const isLoading = useAuthStore((state) => state.isLoading);

  const loginMutation = useLogin();
  const registerMutation = useRegister();
  const logoutMutation = useLogout();

  return {
    user,
    isAuthenticated,
    isLoading,
    login: loginMutation.mutate,
    loginAsync: loginMutation.mutateAsync,
    isLoggingIn: loginMutation.isPending,
    loginError: loginMutation.error,
    register: registerMutation.mutate,
    registerAsync: registerMutation.mutateAsync,
    isRegistering: registerMutation.isPending,
    registerError: registerMutation.error,
    logout: logoutMutation.mutate,
    isLoggingOut: logoutMutation.isPending,
  };
}
