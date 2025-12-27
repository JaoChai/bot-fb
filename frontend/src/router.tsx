import { createBrowserRouter, Navigate } from "react-router"
import { RootLayout } from "@/components/layout/RootLayout"
import { AuthLayout } from "@/components/layout/AuthLayout"
import { ProtectedRoute, GuestRoute } from "@/components/auth"
import { LoginPage } from "@/pages/auth/LoginPage"
import { RegisterPage } from "@/pages/auth/RegisterPage"
import { DashboardPage } from "@/pages/DashboardPage"
import { BotsPage } from "@/pages/BotsPage"
import { BotSettingsPage } from "@/pages/BotSettingsPage"
import { BotEditPage } from "@/pages/BotEditPage"
import { KnowledgeBasePage } from "@/pages/KnowledgeBasePage"
import { ConversationsPage } from "@/pages/ConversationsPage"
import { ConversationDetailPage } from "@/pages/ConversationDetailPage"
import { SettingsPage } from "@/pages/SettingsPage"
import { FlowEditorPage } from "@/pages/FlowEditorPage"
import { AddConnectionPage } from "@/pages/AddConnectionPage"
import { EditConnectionPage } from "@/pages/EditConnectionPage"

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

  // Protected routes (authenticated only) - with main sidebar
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
            path: "connections/add",
            element: <AddConnectionPage />,
          },
          {
            path: "connections/new",
            element: <EditConnectionPage />,
          },
          {
            path: "bots/:botId/settings",
            element: <BotSettingsPage />,
          },
          {
            path: "bots/:botId/edit",
            element: <EditConnectionPage />,
          },
          {
            path: "bots/:botId/edit-old",
            element: <BotEditPage />,
          },
          {
            path: "knowledge-base",
            element: <KnowledgeBasePage />,
          },
          {
            path: "chat",
            element: <ConversationsPage />,
          },
          {
            path: "conversations/:conversationId",
            element: <ConversationDetailPage />,
          },
          {
            path: "settings",
            element: <SettingsPage />,
          },
        ],
      },
    ],
  },

  // Flow Editor routes - standalone layout (no main sidebar, dabby.io style)
  {
    element: <ProtectedRoute />,
    children: [
      {
        path: "/flows/editor",
        element: <FlowEditorPage />,
      },
      {
        path: "/flows/new",
        element: <FlowEditorPage />,
      },
      {
        path: "/flows/:flowId/edit",
        element: <FlowEditorPage />,
      },
    ],
  },
])
