/**
 * Diagnose Tool Implementation
 * System diagnostics for backend, frontend, database, queue, etc.
 */
import type { DiagnoseInput } from "../types/inputs.js";
import type { ServerConfig } from "../utils/config.js";
import { type ToolResult } from "../types/common.js";
export declare function handleDiagnose(input: DiagnoseInput, config: ServerConfig): Promise<ToolResult>;
//# sourceMappingURL=diagnose.d.ts.map