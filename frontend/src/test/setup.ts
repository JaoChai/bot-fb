import "@testing-library/jest-dom/vitest"
import { cleanup } from "@testing-library/react"
import { afterEach, beforeAll, afterAll } from "vitest"
import { server } from "./mocks/server"

// MSW server lifecycle
beforeAll(() => server.listen({ onUnhandledRequest: "bypass" }))
afterEach(() => {
  cleanup()
  server.resetHandlers()
})
afterAll(() => server.close())

// Mock localStorage
const localStorageMock = (() => {
  let store: Record<string, string> = {}
  return {
    getItem: (key: string) => store[key] ?? null,
    setItem: (key: string, value: string) => {
      store[key] = value
    },
    removeItem: (key: string) => {
      delete store[key]
    },
    clear: () => {
      store = {}
    },
    get length() {
      return Object.keys(store).length
    },
    key: (index: number) => Object.keys(store)[index] ?? null,
  }
})()

Object.defineProperty(window, "localStorage", { value: localStorageMock })

// Mock import.meta.env
Object.defineProperty(import.meta, "env", {
  value: {
    VITE_API_URL: "http://localhost:8000/api",
    VITE_REVERB_APP_KEY: "test-key",
    VITE_REVERB_HOST: "localhost",
    VITE_REVERB_PORT: "8080",
    VITE_REVERB_SCHEME: "http",
    MODE: "test",
    DEV: true,
    PROD: false,
  },
})
