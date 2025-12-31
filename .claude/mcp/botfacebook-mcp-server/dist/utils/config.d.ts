/**
 * Configuration loader for BotFacebook MCP Server
 */
export interface ServerConfig {
    laravelBaseUrl: string;
    laravelAuthToken?: string;
    laravelPath: string;
    frontendPath: string;
    phpPath: string;
    railwayProject?: string;
    railwayEnvironment: string;
    mode: "local" | "remote";
    productionBackendUrl: string;
    productionFrontendUrl: string;
}
export declare function loadConfig(): ServerConfig;
export declare function isLocalMode(config: ServerConfig): boolean;
export declare function getApiBaseUrl(config: ServerConfig): string;
//# sourceMappingURL=config.d.ts.map