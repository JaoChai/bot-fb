/**
 * AgenticModeSection - Agentic mode configuration
 * Part of 006-bots-refactor feature (T046)
 *
 * Extracts: agentic_mode_enabled toggle, max_iterations,
 *           tool_timeout_ms, hitl_enabled within flow
 */

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { type FlowSectionProps } from './types';

export function AgenticModeSection({ formData, onChange, disabled }: FlowSectionProps) {
  return (
    <div className="space-y-4">
      <h3 className="text-lg font-medium">Agentic Mode</h3>

      {/* Agentic Mode Toggle */}
      <div className="flex items-start gap-4 p-4 border rounded-lg">
        <Switch
          id="agentic-mode"
          checked={formData.agentic_mode_enabled}
          onCheckedChange={(checked) => onChange('agentic_mode_enabled', checked)}
          disabled={disabled}
        />
        <div className="flex-1">
          <div className="flex items-center gap-2">
            <Label htmlFor="agentic-mode" className="font-medium cursor-pointer">
              Enable Agentic Mode
            </Label>
            <Badge variant="secondary">Smarter AI</Badge>
          </div>
          <p className="text-sm text-muted-foreground mt-1">
            Transform AI into an agent that can search, use tools, and make decisions autonomously
          </p>
        </div>
      </div>

      {/* Agentic Mode Settings (shown when enabled) */}
      {formData.agentic_mode_enabled && (
        <div className="space-y-4 pl-4 border-l-2 border-muted">
          {/* Max Iterations */}
          <div className="space-y-2">
            <div className="flex items-center gap-4">
              <Label htmlFor="max-iterations" className="text-sm">
                Max Tool Calls
              </Label>
              <Input
                id="max-iterations"
                type="number"
                min={1}
                max={20}
                value={formData.max_iterations}
                onChange={(e) => {
                  const val = parseInt(e.target.value, 10);
                  if (!isNaN(val)) onChange('max_iterations', val);
                }}
                disabled={disabled}
                className="w-20"
              />
            </div>
            <p className="text-xs text-muted-foreground">
              Maximum number of tool calls per request (1-20). AI will stop automatically when this limit is reached.
            </p>
          </div>

          {/* Tool Timeout */}
          <div className="space-y-2">
            <div className="flex items-center gap-4">
              <Label htmlFor="tool-timeout" className="text-sm">
                Tool Timeout (ms)
              </Label>
              <Input
                id="tool-timeout"
                type="number"
                min={1000}
                max={300000}
                step={1000}
                value={formData.tool_timeout_ms}
                onChange={(e) => {
                  const val = parseInt(e.target.value, 10);
                  if (!isNaN(val)) onChange('tool_timeout_ms', val);
                }}
                disabled={disabled}
                className="w-28"
              />
            </div>
            <p className="text-xs text-muted-foreground">
              Maximum time for each tool call (1,000-300,000 ms). Helps prevent stuck operations.
            </p>
          </div>

          {/* HITL Toggle */}
          <div className="flex items-start gap-4 p-4 border rounded-lg bg-muted/30">
            <Switch
              id="hitl-enabled"
              checked={formData.hitl_enabled}
              onCheckedChange={(checked) => onChange('hitl_enabled', checked)}
              disabled={disabled}
            />
            <div className="flex-1">
              <div className="flex items-center gap-2">
                <Label htmlFor="hitl-enabled" className="font-medium cursor-pointer">
                  Human-in-the-Loop (HITL)
                </Label>
                <Badge variant="outline" className="text-xs">
                  Safety
                </Badge>
              </div>
              <p className="text-sm text-muted-foreground mt-1">
                Require human approval before executing potentially dangerous actions.
                When enabled, the system will pause and wait for confirmation.
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Info box when agentic mode is disabled */}
      {!formData.agentic_mode_enabled && (
        <div className="p-4 bg-muted/50 rounded-lg">
          <p className="text-sm text-muted-foreground">
            Enable Agentic Mode to unlock advanced AI capabilities including tool usage,
            multi-step reasoning, and autonomous decision making.
          </p>
        </div>
      )}
    </div>
  );
}
