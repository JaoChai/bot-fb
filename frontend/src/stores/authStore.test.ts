import { describe, it, expect, beforeEach, vi } from "vitest"
import { useAuthStore } from "./authStore"

// Mock echo module
vi.mock("@/lib/echo", () => ({
  disconnectEcho: vi.fn(),
  reconnectEcho: vi.fn(),
}))

describe("authStore", () => {
  beforeEach(() => {
    // Reset store to initial state
    useAuthStore.setState({
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: true,
    })
    localStorage.clear()
  })

  describe("initial state", () => {
    it("has null user and token", () => {
      const state = useAuthStore.getState()
      expect(state.user).toBeNull()
      expect(state.token).toBeNull()
      expect(state.isAuthenticated).toBe(false)
    })
  })

  describe("login", () => {
    it("sets user, token, and isAuthenticated", () => {
      const user = {
        id: 1,
        name: "Test",
        email: "test@example.com",
        role: "owner" as const,
        email_verified_at: null,
        created_at: "2024-01-01",
        updated_at: "2024-01-01",
      }

      useAuthStore.getState().login(user, "test-token")

      const state = useAuthStore.getState()
      expect(state.user).toEqual(user)
      expect(state.token).toBe("test-token")
      expect(state.isAuthenticated).toBe(true)
      expect(state.isLoading).toBe(false)
    })

    it("stores token in localStorage", () => {
      const user = {
        id: 1,
        name: "Test",
        email: "test@example.com",
        role: "owner" as const,
        email_verified_at: null,
        created_at: "2024-01-01",
        updated_at: "2024-01-01",
      }

      useAuthStore.getState().login(user, "test-token")

      expect(localStorage.getItem("auth_token")).toBe("test-token")
    })
  })

  describe("logout", () => {
    it("clears user, token, and isAuthenticated", () => {
      // Login first
      useAuthStore.setState({
        user: { id: 1, name: "Test", email: "t@t.com", role: "owner", email_verified_at: null, created_at: "", updated_at: "" },
        token: "test-token",
        isAuthenticated: true,
        isLoading: false,
      })
      localStorage.setItem("auth_token", "test-token")

      useAuthStore.getState().logout()

      const state = useAuthStore.getState()
      expect(state.user).toBeNull()
      expect(state.token).toBeNull()
      expect(state.isAuthenticated).toBe(false)
      expect(localStorage.getItem("auth_token")).toBeNull()
    })
  })

  describe("setUser", () => {
    it("sets user and updates isAuthenticated", () => {
      const user = {
        id: 1,
        name: "Test",
        email: "test@example.com",
        role: "owner" as const,
        email_verified_at: null,
        created_at: "2024-01-01",
        updated_at: "2024-01-01",
      }

      useAuthStore.getState().setUser(user)

      expect(useAuthStore.getState().user).toEqual(user)
      expect(useAuthStore.getState().isAuthenticated).toBe(true)
    })

    it("clears authentication when user is null", () => {
      useAuthStore.getState().setUser(null)

      expect(useAuthStore.getState().user).toBeNull()
      expect(useAuthStore.getState().isAuthenticated).toBe(false)
    })
  })

  describe("setToken", () => {
    it("sets token in state and localStorage", () => {
      useAuthStore.getState().setToken("new-token")

      expect(useAuthStore.getState().token).toBe("new-token")
      expect(localStorage.getItem("auth_token")).toBe("new-token")
    })

    it("removes token from localStorage when null", () => {
      localStorage.setItem("auth_token", "old-token")
      useAuthStore.getState().setToken(null)

      expect(useAuthStore.getState().token).toBeNull()
      expect(localStorage.getItem("auth_token")).toBeNull()
    })
  })

  describe("setLoading", () => {
    it("updates loading state", () => {
      useAuthStore.getState().setLoading(false)
      expect(useAuthStore.getState().isLoading).toBe(false)

      useAuthStore.getState().setLoading(true)
      expect(useAuthStore.getState().isLoading).toBe(true)
    })
  })
})
