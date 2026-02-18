import { Suspense } from "react"
import { createBrowserRouter, Navigate } from "react-router"
import { RootLayout } from "@/components/layout/RootLayout"
import { AuthLayout } from "@/components/layout/AuthLayout"
import { ProtectedRoute, GuestRoute } from "@/components/auth"
import { PageLoadingFallback } from "@/components/ui/loading-spinner"
import { lazyWithRetryNamed } from "@/lib/lazyWithRetry"
import { ChunkErrorBoundary } from "@/components/error/ChunkErrorBoundary"

// Lazy load auth pages with retry support
const LoginPage = lazyWithRetryNamed(() => import("@/pages/auth/LoginPage"), "LoginPage")
const RegisterPage = lazyWithRetryNamed(() => import("@/pages/auth/RegisterPage"), "RegisterPage")

// Lazy load main pages with retry support
const DashboardPage = lazyWithRetryNamed(() => import("@/pages/DashboardPage"), "DashboardPage")
const BotsPage = lazyWithRetryNamed(() => import("@/pages/BotsPage"), "BotsPage")
const BotSettingsPage = lazyWithRetryNamed(() => import("@/pages/BotSettingsPage"), "BotSettingsPage")
const BotEditPage = lazyWithRetryNamed(() => import("@/pages/BotEditPage"), "BotEditPage")
const KnowledgeBasePage = lazyWithRetryNamed(() => import("@/pages/KnowledgeBasePage"), "KnowledgeBasePage")
const ChatPage = lazyWithRetryNamed(() => import("@/pages/ChatPage"), "ChatPage")
const SettingsPage = lazyWithRetryNamed(() => import("@/pages/SettingsPage"), "SettingsPage")
const FlowEditorPage = lazyWithRetryNamed(() => import("@/pages/FlowEditorPage"), "FlowEditorPage")
const AddConnectionPage = lazyWithRetryNamed(() => import("@/pages/AddConnectionPage"), "AddConnectionPage")
const EditConnectionPage = lazyWithRetryNamed(() => import("@/pages/EditConnectionPage"), "EditConnectionPage")
const EvaluationsPage = lazyWithRetryNamed(() => import("@/pages/EvaluationsPage"), "EvaluationsPage")
const EvaluationDetailPage = lazyWithRetryNamed(() => import("@/pages/EvaluationDetailPage"), "EvaluationDetailPage")
const TeamPage = lazyWithRetryNamed(() => import("@/pages/TeamPage"), "TeamPage")
const QuickRepliesPage = lazyWithRetryNamed(() => import("@/pages/settings/QuickRepliesPage"), "QuickRepliesPage")
const QAInspectorPage = lazyWithRetryNamed(() => import("@/pages/QAInspectorPage"), "QAInspectorPage")
const QAWeeklyReportPage = lazyWithRetryNamed(() => import("@/pages/QAWeeklyReportPage"), "QAWeeklyReportPage")
const OrdersReportPage = lazyWithRetryNamed(() => import("@/pages/OrdersReportPage"), "OrdersReportPage")

// Wrapper component for lazy loaded pages with error boundary
function LazyPage({ children }: { children: React.ReactNode }) {
  return (
    <ChunkErrorBoundary>
      <Suspense fallback={<PageLoadingFallback />}>{children}</Suspense>
    </ChunkErrorBoundary>
  )
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
            element: <LazyPage><ChatPage /></LazyPage>,
          },
          {
            path: "settings",
            element: <LazyPage><SettingsPage /></LazyPage>,
          },
          {
            path: "settings/quick-replies",
            element: <LazyPage><QuickRepliesPage /></LazyPage>,
          },
          {
            path: "evaluations",
            element: <LazyPage><EvaluationsPage /></LazyPage>,
          },
          {
            path: "evaluations/:evaluationId",
            element: <LazyPage><EvaluationDetailPage /></LazyPage>,
          },
          // Redirect old /telegram route to /chat with telegram filter
          {
            path: "telegram",
            element: <Navigate to="/chat" replace />,
          },
          {
            path: "team",
            element: <LazyPage><TeamPage /></LazyPage>,
          },
          {
            path: "orders",
            element: <LazyPage><OrdersReportPage /></LazyPage>,
          },
          {
            path: "bots/:botId/qa-inspector",
            element: <LazyPage><QAInspectorPage /></LazyPage>,
          },
          {
            path: "bots/:botId/qa-inspector/reports/:reportId",
            element: <LazyPage><QAWeeklyReportPage /></LazyPage>,
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
