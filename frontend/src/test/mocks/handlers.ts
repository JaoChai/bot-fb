import { http, HttpResponse } from "msw"

const API_URL = "http://localhost:8000/api"

export const handlers = [
  // Auth endpoints
  http.post(`${API_URL}/auth/login`, async ({ request }) => {
    const body = (await request.json()) as { email: string; password: string }

    if (body.email === "test@example.com" && body.password === "password") {
      return HttpResponse.json({
        data: {
          user: {
            id: 1,
            name: "Test User",
            email: "test@example.com",
            role: "owner",
            email_verified_at: "2024-01-01T00:00:00.000000Z",
            created_at: "2024-01-01T00:00:00.000000Z",
            updated_at: "2024-01-01T00:00:00.000000Z",
          },
          token: "test-token-123",
        },
      })
    }

    return HttpResponse.json(
      { message: "Invalid credentials" },
      { status: 401 }
    )
  }),

  http.get(`${API_URL}/auth/user`, ({ request }) => {
    const authHeader = request.headers.get("Authorization")

    if (authHeader === "Bearer test-token-123") {
      return HttpResponse.json({
        data: {
          id: 1,
          name: "Test User",
          email: "test@example.com",
          role: "owner",
          email_verified_at: "2024-01-01T00:00:00.000000Z",
          created_at: "2024-01-01T00:00:00.000000Z",
          updated_at: "2024-01-01T00:00:00.000000Z",
        },
      })
    }

    return HttpResponse.json({ message: "Unauthenticated" }, { status: 401 })
  }),

  http.post(`${API_URL}/auth/logout`, () => {
    return HttpResponse.json({ message: "Logged out" })
  }),

  // Health check
  http.get(`${API_URL}/../up`, () => {
    return new HttpResponse(null, { status: 200 })
  }),
]
