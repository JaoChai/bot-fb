import { StrictMode } from "react"
import { createRoot } from "react-dom/client"
import { RouterProvider } from "react-router/dom"
import { PersistQueryClientProvider } from "@tanstack/react-query-persist-client"
import { ReactQueryDevtools } from "@tanstack/react-query-devtools"
import * as Sentry from "@sentry/react"
import { Toaster } from "@/components/ui/sonner"
import { queryClient, persister, shouldDehydrateQuery } from "./lib/query"
import { router } from "./router"
import { reportWebVitals } from "./lib/webVitals"
import "./index.css"

// Self-hosted fonts (replace render-blocking Google Fonts), subset-specific to a
// Thai+English app: Inter Latin glyphs + Noto Sans Thai Thai/Latin glyphs.
// Weights 400/500/600/700 (Noto 300/font-light is unused). unicode-range means
// the browser only fetches the subsets it needs.
import "@fontsource/inter/latin-400.css"
import "@fontsource/inter/latin-500.css"
import "@fontsource/inter/latin-600.css"
import "@fontsource/inter/latin-700.css"
import "@fontsource/inter/latin-ext-400.css"
import "@fontsource/inter/latin-ext-500.css"
import "@fontsource/inter/latin-ext-600.css"
import "@fontsource/inter/latin-ext-700.css"
import "@fontsource/noto-sans-thai/thai-400.css"
import "@fontsource/noto-sans-thai/thai-500.css"
import "@fontsource/noto-sans-thai/thai-600.css"
import "@fontsource/noto-sans-thai/thai-700.css"
import "@fontsource/noto-sans-thai/latin-400.css"
import "@fontsource/noto-sans-thai/latin-500.css"
import "@fontsource/noto-sans-thai/latin-600.css"
import "@fontsource/noto-sans-thai/latin-700.css"

// Initialize Sentry for error monitoring
if (import.meta.env.VITE_SENTRY_DSN) {
  Sentry.init({
    dsn: import.meta.env.VITE_SENTRY_DSN,
    environment: import.meta.env.VITE_SENTRY_ENVIRONMENT || "development",
    integrations: [
      Sentry.browserTracingIntegration(),
    ],
    tracesSampleRate: 0.1,
  })
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <PersistQueryClientProvider
      client={queryClient}
      persistOptions={{
        persister,
        buster: 'v3',
        dehydrateOptions: {
          shouldDehydrateQuery,
        },
      }}
    >
      <RouterProvider router={router} />
      <Toaster position="top-right" />
      <ReactQueryDevtools initialIsOpen={false} />
    </PersistQueryClientProvider>
  </StrictMode>
)

// Report Web Vitals for performance monitoring
reportWebVitals()
