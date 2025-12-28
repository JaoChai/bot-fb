import { lazy, Suspense } from "react"
import { createBrowserRouter, Navigate } from "react-router"
import { RootLayout } from "@/components/layout/RootLayout"
import { AuthLayout } from "@/components/layout/AuthLayout"
import { ProtectedRoute, GuestRoute } from "@/components/auth"
import { PageLoadingFallback } from "@/components/ui/loading-spinner"

// Lazy load auth pages
const LoginPage = lazy(() => import("@/pages/auth/LoginPage").then(m => ({ default: m.LoginPage })))
const RegisterPage = lazy(() => import("@/pages/auth/RegisterPage").then(m => ({ default: m.RegisterPage })))

// Lazy load main pages
const DashboardPage = lazy(() => import("@/pages/DashboardPage").then(m => ({ default: m.DashboardPage })))
const BotsPage = lazy(() => import("@/pages/BotsPage").then(m => ({ default: m.BotsPage })))
const BotSettingsPage = lazy(() => import("@/pages/BotSettingsPage").then(m => ({ default: m.BotSettingsPage })))
const BotEditPage = lazy(() => import("@/pages/BotEditPage").then(m => ({ default: m.BotEditPage })))
const KnowledgeBasePage = lazy(() => import("@/pages/KnowledgeBasePage").then(m => ({ default: m.KnowledgeBasePage })))
const ConversationsPage = lazy(() => import("@/pages/ConversationsPage").then(m => ({ default: m.ConversationsPage })))
const ConversationDetailPage = lazy(() => import("@/pages/ConversationDetailPage").then(m => ({ default: m.ConversationDetailPage })))
const SettingsPage = lazy(() => import("@/pages/SettingsPage").then(m => ({ default: m.SettingsPage })))
const FlowEditorPage = lazy(() => import("@/pages/FlowEditorPage").then(m => ({ default: m.FlowEditorPage })))
const AddConnectionPage = lazy(() => import("@/pages/AddConnectionPage").then(m => ({ default: m.AddConnectionPage })))
const EditConnectionPage = lazy(() => import("@/pages/EditConnectionPage").then(m => ({ default: m.EditConnectionPage })))

// Wrapper component for lazy loaded pages
function LazyPage({ children }: { children: React.ReactNode }) {
  return <Suspense fallback={<PageLoadingFallback />}>{children}</Suspense>
}

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
            element: <LazyPage><LoginPage /></LazyPage>,
          },
          {
            path: "/register",
            element: <LazyPage><RegisterPage /></LazyPage>,
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
            element: <LazyPage><DashboardPage /></LazyPage>,
          },
          {
            path: "bots",
            element: <LazyPage><BotsPage /></LazyPage>,
          },
          {
            path: "connections/add",
            element: <LazyPage><AddConnectionPage /></LazyPage>,
          },
          {
            path: "connections/new",
            element: <LazyPage><EditConnectionPage /></LazyPage>,
          },
          {
            path: "bots/:botId/settings",
            element: <LazyPage><BotSettingsPage /></LazyPage>,
          },
          {
            path: "bots/:botId/edit",
            element: <LazyPage><EditConnectionPage /></LazyPage>,
          },
          {
            path: "bots/:botId/edit-old",
            element: <LazyPage><BotEditPage /></LazyPage>,
          },
          {
            path: "knowledge-base",
            element: <LazyPage><KnowledgeBasePage /></LazyPage>,
          },
          {
            path: "chat",
            element: <LazyPage><ConversationsPage /></LazyPage>,
          },
          {
            path: "conversations/:conversationId",
            element: <LazyPage><ConversationDetailPage /></LazyPage>,
          },
          {
            path: "settings",
            element: <LazyPage><SettingsPage /></LazyPage>,
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
        element: <LazyPage><FlowEditorPage /></LazyPage>,
      },
      {
        path: "/flows/new",
        element: <LazyPage><FlowEditorPage /></LazyPage>,
      },
      {
        path: "/flows/:flowId/edit",
        element: <LazyPage><FlowEditorPage /></LazyPage>,
      },
    ],
  },
])
