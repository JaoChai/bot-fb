import { createBrowserRouter, Navigate } from "react-router"
import { RootLayout } from "@/components/layout/RootLayout"
import { AuthLayout } from "@/components/layout/AuthLayout"
import { ProtectedRoute, GuestRoute } from "@/components/auth"
import { HomePage } from "@/pages/HomePage"
import { LoginPage } from "@/pages/auth/LoginPage"
import { RegisterPage } from "@/pages/auth/RegisterPage"

export const router = createBrowserRouter([
  // Auth routes (guest only)
  {
    element: <GuestRoute />,
    children: [
      {
        element: <AuthLayout />,
        children: [
          {
            path: "/login",
            element: <LoginPage />,
          },
          {
            path: "/register",
            element: <RegisterPage />,
          },
        ],
      },
    ],
  },

  // Protected routes (authenticated only)
  {
    element: <ProtectedRoute />,
    children: [
      {
        path: "/",
        element: <RootLayout />,
        children: [
          {
            index: true,
            element: <Navigate to="/dashboard" replace />,
          },
          {
            path: "dashboard",
            element: <HomePage />,
          },
        ],
      },
    ],
  },
])
