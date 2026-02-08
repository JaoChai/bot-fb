import path from "path"
import tailwindcss from "@tailwindcss/vite"
import react from "@vitejs/plugin-react"
import { defineConfig } from "vite"

// https://vite.dev/config/
export default defineConfig({
  plugins: [tailwindcss(), react()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  build: {
    // Chunk size warnings
    chunkSizeWarningLimit: 500,
    // Minification
    minify: "esbuild",
    // Source maps disabled for production
    sourcemap: false,
    // Rollup options for chunk splitting
    rollupOptions: {
      output: {
        manualChunks: {
          // Core React
          "vendor-react": ["react", "react-dom", "react-router"],
          // UI Components (Radix)
          "vendor-radix": [
            "@radix-ui/react-dialog",
            "@radix-ui/react-dropdown-menu",
            "@radix-ui/react-tabs",
            "@radix-ui/react-select",
            "@radix-ui/react-avatar",
            "@radix-ui/react-tooltip",
            "@radix-ui/react-scroll-area",
            "@radix-ui/react-switch",
            "@radix-ui/react-slider",
            "@radix-ui/react-collapsible",
            "@radix-ui/react-alert-dialog",
            "@radix-ui/react-progress",
            "@radix-ui/react-separator",
            "@radix-ui/react-label",
            "@radix-ui/react-slot",
          ],
          // Data Fetching
          "vendor-query": ["@tanstack/react-query", "@tanstack/react-virtual", "axios"],
          // Charts
          "vendor-charts": ["recharts"],
          // Icons
          "vendor-icons": ["lucide-react"],
          // Utilities
          "vendor-utils": ["date-fns", "clsx", "tailwind-merge", "class-variance-authority", "zod"],
          // State & Forms
          "vendor-state": ["zustand", "react-hook-form", "@hookform/resolvers"],
        },
      },
    },
  },
})
