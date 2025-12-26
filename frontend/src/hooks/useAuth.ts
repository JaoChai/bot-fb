import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router';
import { apiGet, apiPost } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import { useAuthStore } from '@/stores/authStore';
import type {
  User,
  LoginCredentials,
  RegisterCredentials,
  AuthResponse,
} from '@/types/api';

// Fetch current user
export function useUser() {
  const { token, setUser, setLoading, logout } = useAuthStore();

  return useQuery({
    queryKey: queryKeys.auth.user(),
    queryFn: async () => {
      const response = await apiGet<User>('/auth/user');
      return response;
    },
    enabled: !!token,
    retry: false,
    staleTime: 10 * 60 * 1000, // 10 minutes
    select: (data) => {
      setUser(data);
      setLoading(false);
      return data;
    },
    // Handle errors
    meta: {
      onError: () => {
        logout();
      },
    },
  });
}

// Login mutation
export function useLogin() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const { login } = useAuthStore();

  return useMutation({
    mutationFn: async (credentials: LoginCredentials) => {
      const response = await apiPost<AuthResponse>('/auth/login', credentials);
      return response;
    },
    onSuccess: (data) => {
      login(data.user, data.token);
      queryClient.setQueryData(queryKeys.auth.user(), data.user);
      navigate('/dashboard');
    },
  });
}

// Register mutation
export function useRegister() {
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
export function useLogout() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const { logout } = useAuthStore();

  return useMutation({
    mutationFn: async () => {
      await apiPost('/auth/logout');
    },
    onSettled: () => {
      // Always logout locally, even if API call fails
      logout();
      queryClient.clear();
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
