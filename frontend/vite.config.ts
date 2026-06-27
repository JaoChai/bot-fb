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
    // Minification (Vite 8 default bundler is Rolldown; oxc replaces esbuild)
    minify: "oxc",
    // Source maps disabled for production
    sourcemap: false,
    // Chunk splitting — Vite 8/Rolldown replaced object-form manualChunks with
    // codeSplitting.groups. `test` matches the full module id path; groups are
    // evaluated in order (first match wins).
    rolldownOptions: {
      output: {
        codeSplitting: {
          groups: [
            { name: "vendor-react", test: /[/\\]node_modules[/\\](react|react-dom|react-router)[/\\]/ },
            { name: "vendor-radix", test: /[/\\]node_modules[/\\]@radix-ui[/\\]/ },
            { name: "vendor-query", test: /[/\\]node_modules[/\\](@tanstack[/\\]react-query|@tanstack[/\\]react-virtual|axios)[/\\]/ },
            { name: "vendor-charts", test: /[/\\]node_modules[/\\]recharts[/\\]/ },
            { name: "vendor-icons", test: /[/\\]node_modules[/\\]lucide-react[/\\]/ },
            { name: "vendor-utils", test: /[/\\]node_modules[/\\](date-fns|clsx|tailwind-merge|class-variance-authority|zod)[/\\]/ },
            { name: "vendor-state", test: /[/\\]node_modules[/\\](zustand|react-hook-form|@hookform[/\\]resolvers)[/\\]/ },
          ],
        },
      },
    },
  },
})
