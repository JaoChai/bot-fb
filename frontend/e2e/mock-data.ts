// E2E mock data. Shapes copied from src/types/api.ts (User) and the flat
// AuthResponse contract (backend AuthController returns `user` + `token` at the
// top level) — keep in sync with both.

export const user = {
  id: 1,
  name: 'E2E Owner',
  email: 'e2e@example.com',
  role: 'owner',
  email_verified_at: '2026-01-01T00:00:00.000000Z',
  created_at: '2026-01-01T00:00:00.000000Z',
  updated_at: '2026-01-01T00:00:00.000000Z',
} as const

export const token = 'e2e-token'
