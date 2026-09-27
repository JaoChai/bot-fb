import path from "path"
import tailwindcss from "@tailwindcss/vite"
import react, { reactCompilerPreset } from "@vitejs/plugin-react"
import babel from "@rolldown/plugin-babel"
import { defineConfig } from "vite"

// https://vite.dev/config/
export default defineConfig({
  // React Compiler via @rolldown/plugin-babel (plugin-react >= 6 no longer takes a `babel` option)
  plugins: [tailwindcss(), react(), babel({ presets: [reactCompilerPreset()] })],
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
    // codeSplitting.groups. `test` matches the full module id path; the explicit
    // `priority` (not array order) decides which group owns a module, and
    // vendor-charts sits lowest so shared deps (clsx, react) land in the eager groups.
    rolldownOptions: {
      output: {
        codeSplitting: {
          groups: [
            { name: "vendor-react", test: /[/\\]node_modules[/\\](react|react-dom|react-router)[/\\]/, priority: 60 },
            { name: "vendor-utils", test: /[/\\]node_modules[/\\](date-fns|clsx|tailwind-merge|class-variance-authority|zod)[/\\]/, priority: 50 },
            { name: "vendor-radix", test: /[/\\]node_modules[/\\](radix-ui|@radix-ui)[/\\]/, priority: 40 },
            { name: "vendor-query", test: /[/\\]node_modules[/\\](@tanstack[/\\]react-query|@tanstack[/\\]react-virtual|axios)[/\\]/, priority: 40 },
            { name: "vendor-state", test: /[/\\]node_modules[/\\](zustand|react-hook-form|@hookform[/\\]resolvers)[/\\]/, priority: 40 },
            { name: "vendor-icons", test: /[/\\]node_modules[/\\]lucide-react[/\\]/, priority: 40 },
            { name: "vendor-charts", test: /[/\\]node_modules[/\\]recharts[/\\]/, priority: 10 },
          ],
        },
      },
    },
  },
})
