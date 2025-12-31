/**
 * Configuration loader for BotFacebook MCP Server
 */

export interface ServerConfig {
  // Laravel API
  laravelBaseUrl: string;
  laravelAuthToken?: string;

  // Local paths
  laravelPath: string;
  frontendPath: string;
  phpPath: string;

  // Railway
  railwayProject?: string;
  railwayEnvironment: string;

  // Mode
  mode: "local" | "remote";

  // Production URLs
  productionBackendUrl: string;
  productionFrontendUrl: string;
}

export function loadConfig(): ServerConfig {
  return {
    laravelBaseUrl: process.env.LARAVEL_API_URL || "http://localhost:8000",
    laravelAuthToken: process.env.LARAVEL_AUTH_TOKEN,
    laravelPath: process.env.LARAVEL_PATH || "./backend",
    frontendPath: process.env.FRONTEND_PATH || "./frontend",
    phpPath: process.env.PHP_PATH || "php",
    railwayProject: process.env.RAILWAY_PROJECT,
    railwayEnvironment: process.env.RAILWAY_ENVIRONMENT || "production",
    mode: (process.env.MCP_MODE as "local" | "remote") || "local",
    productionBackendUrl: process.env.PRODUCTION_BACKEND_URL || "https://backend-production-b216.up.railway.app",
    productionFrontendUrl: process.env.PRODUCTION_FRONTEND_URL || "https://frontend-production-9fe8.up.railway.app",
  };
}

export function isLocalMode(config: ServerConfig): boolean {
  return config.mode === "local";
}

export function getApiBaseUrl(config: ServerConfig): string {
  return isLocalMode(config) ? config.laravelBaseUrl : config.productionBackendUrl;
}
