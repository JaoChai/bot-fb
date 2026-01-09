/**
 * SafetySettingsSection - Safety/guardrails settings for flow editor
 * Part of 006-bots-refactor feature (T048)
 *
 * Controls:
 * - safety_max_cost_usd (max cost limit)
 * - safety_max_timeout_sec (max timeout)
 * - safety_max_turns (max conversation turns)
 *
 * Copied from frontend and adapted for Inertia context
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Slider } from '@/Components/ui/slider';
import { Input } from '@/Components/ui/input';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/Components/ui/tooltip';
import { Shield, Clock, DollarSign, MessageSquare, Info, AlertTriangle } from 'lucide-react';
import { type FlowSectionProps } from './types';

const InfoTooltip = ({ children }: { children: React.ReactNode }) => (
  <TooltipProvider>
    <Tooltip>
      <TooltipTrigger asChild>
        <Info className="h-4 w-4 text-muted-foreground cursor-help" />
      </TooltipTrigger>
      <TooltipContent className="max-w-xs">
        <p className="text-sm">{children}</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
);

export function SafetySettingsSection({
  formData,
  onChange,
  disabled = false,
}: FlowSectionProps) {
  return (
    <div className="border rounded-lg p-6 space-y-4">
      {/* Header */}
      <div className="flex items-center gap-3">
        <div className="p-2 rounded-lg bg-orange-500/10">
          <Shield className="h-5 w-5 text-orange-500" />
        </div>
        <div>
          <h3 className="font-semibold">Safety Controls</h3>
          <p className="text-sm text-muted-foreground">
            Prevent runaway costs and control AI usage
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Max Cost */}
        <Card>
          <CardHeader className="pb-3">
            <div className="flex items-center gap-2">
              <DollarSign className="h-4 w-4 text-muted-foreground" />
              <CardTitle className="text-base">Cost Limit</CardTitle>
            </div>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex items-center gap-2">
              <Label className="font-medium text-sm">Max Cost per Session</Label>
              <InfoTooltip>
                Stop conversation if cost exceeds this limit (USD). Prevents runaway costs.
              </InfoTooltip>
            </div>
            <div className="flex items-center gap-2">
              <span className="text-muted-foreground">$</span>
              <Input
                type="number"
                step="0.1"
                min="0.1"
                max="50"
                placeholder="1.00"
                value={formData.safety_max_cost_usd ?? ''}
                onChange={(e) => {
                  const val = e.target.value ? parseFloat(e.target.value) : null;
                  onChange('safety_max_cost_usd', val);
                }}
                disabled={disabled}
                className="w-24"
              />
              <span className="text-xs text-muted-foreground">USD</span>
            </div>
            <p className="text-xs text-muted-foreground">
              Leave empty = unlimited
            </p>
          </CardContent>
        </Card>

        {/* Max Timeout */}
        <Card>
          <CardHeader className="pb-3">
            <div className="flex items-center gap-2">
              <Clock className="h-4 w-4 text-muted-foreground" />
              <CardTitle className="text-base">Timeout</CardTitle>
            </div>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Label className="font-medium text-sm">Max Response Time</Label>
                <InfoTooltip>
                  Stop response if it takes too long. Prevents infinite loops.
                </InfoTooltip>
              </div>
              <span className="text-sm font-mono bg-muted px-2 py-1 rounded">
                {formData.safety_max_timeout_sec ?? 60} sec
              </span>
            </div>
            <Slider
              min={10}
              max={300}
              step={10}
              value={[formData.safety_max_timeout_sec ?? 60]}
              onValueChange={(value) => onChange('safety_max_timeout_sec', value[0])}
              disabled={disabled}
              className="cursor-pointer"
            />
            <div className="flex justify-between text-xs text-muted-foreground">
              <span>10 sec</span>
              <span>5 min</span>
            </div>
          </CardContent>
        </Card>

        {/* Max Turns */}
        <Card>
          <CardHeader className="pb-3">
            <div className="flex items-center gap-2">
              <MessageSquare className="h-4 w-4 text-muted-foreground" />
              <CardTitle className="text-base">Conversation Limit</CardTitle>
            </div>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Label className="font-medium text-sm">Max Turns</Label>
                <InfoTooltip>
                  Limit turns per session. Prevents context from getting too long.
                </InfoTooltip>
              </div>
              <span className="text-sm font-mono bg-muted px-2 py-1 rounded">
                {formData.safety_max_turns ?? 50} turns
              </span>
            </div>
            <Slider
              min={5}
              max={100}
              step={5}
              value={[formData.safety_max_turns ?? 50]}
              onValueChange={(value) => onChange('safety_max_turns', value[0])}
              disabled={disabled}
              className="cursor-pointer"
            />
            <div className="flex justify-between text-xs text-muted-foreground">
              <span>5 turns</span>
              <span>100 turns</span>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Warning about no limits */}
      {!formData.safety_max_cost_usd &&
        !formData.safety_max_timeout_sec &&
        !formData.safety_max_turns && (
          <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-lg p-3 text-sm">
            <div className="flex gap-2">
              <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
              <p className="text-amber-800 dark:text-amber-200">
                No safety limits configured. We recommend setting at least 1 limit to prevent
                runaway costs and infinite loops.
              </p>
            </div>
          </div>
        )}

      {/* Info box */}
      <div className="bg-muted/50 rounded-lg p-3 text-sm">
        <p className="text-muted-foreground">
          Safety Controls help prevent issues that may arise from AI usage such as exceeding budget
          or AI not finishing responses. When limits are reached, the system will notify users and stop automatically.
        </p>
      </div>
    </div>
  );
}
