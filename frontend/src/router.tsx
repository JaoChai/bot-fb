import { createBrowserRouter, Navigate } from "react-router"
import { RootLayout } from "@/components/layout/RootLayout"
import { AuthLayout } from "@/components/layout/AuthLayout"
import { ProtectedRoute, GuestRoute } from "@/components/auth"
import { LoginPage } from "@/pages/auth/LoginPage"
import { RegisterPage } from "@/pages/auth/RegisterPage"
import { DashboardPage } from "@/pages/DashboardPage"
import { BotsPage } from "@/pages/BotsPage"
import { BotSettingsPage } from "@/pages/BotSettingsPage"
import { KnowledgeBasePage } from "@/pages/KnowledgeBasePage"
import { ChatPage } from "@/pages/ChatPage"
import { SettingsPage } from "@/pages/SettingsPage"
import { FlowsPage } from "@/pages/FlowsPage"
import { FlowEditorPage } from "@/pages/FlowEditorPage"

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
            element: <DashboardPage />,
          },
          {
            path: "bots",
            element: <BotsPage />,
          },
          {
            path: "bots/:botId/settings",
            element: <BotSettingsPage />,
          },
          {
            path: "knowledge-base",
            element: <KnowledgeBasePage />,
          },
          {
            path: "chat",
            element: <ChatPage />,
          },
          {
            path: "settings",
            element: <SettingsPage />,
          },
          {
            path: "flows",
            element: <FlowsPage />,
          },
          {
            path: "flows/new",
            element: <FlowEditorPage />,
          },
          {
            path: "flows/:flowId/edit",
            element: <FlowEditorPage />,
          },
        ],
      },
    ],
  },
])
