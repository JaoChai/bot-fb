/**
 * FlowSafetySettings - Agent safety settings component
 *
 * Copied from frontend and adapted for Inertia context
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Slider } from '@/Components/ui/slider';
import { Input } from '@/Components/ui/input';
import { Collapsible, CollapsibleContent } from '@/Components/ui/collapsible';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import {
  Shield,
  Clock,
  DollarSign,
  AlertTriangle,
  Info,
  HandMetal
} from 'lucide-react';

export interface FlowSafetySettingsData {
  agent_timeout_seconds: number;
  agent_max_cost_per_request: number | null;
  hitl_enabled: boolean;
  hitl_dangerous_actions: string[];
}

interface FlowSafetySettingsProps {
  settings: FlowSafetySettingsData;
  onChange: <K extends keyof FlowSafetySettingsData>(
    field: K,
    value: FlowSafetySettingsData[K]
  ) => void;
}

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

// Synced with backend AgentSafetyService.php $defaultDangerousPatterns
// Using wildcard patterns for broader matching
const DANGEROUS_ACTIONS = [
  { id: 'send_email', label: 'Send Email', description: 'Send email on behalf of user' },
  { id: 'make_payment', label: 'Make Payment', description: 'Process payment or money transfer' },
  { id: 'delete_*', label: 'Delete Data', description: 'Delete data in system (includes remove, destroy, drop)' },
  { id: 'update_*', label: 'Update Data', description: 'Update or modify database data' },
  { id: 'call_external_api', label: 'External API', description: 'Call external API or webhook' },
];

export function FlowSafetySettings({ settings, onChange }: FlowSafetySettingsProps) {
  const handleDangerousActionToggle = (actionId: string, enabled: boolean) => {
    const current = settings.hitl_dangerous_actions || [];
    if (enabled) {
      onChange('hitl_dangerous_actions', [...current, actionId]);
    } else {
      onChange('hitl_dangerous_actions', current.filter((a) => a !== actionId));
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3 mb-4">
        <div className="p-2 rounded-lg bg-orange-500/10">
          <Shield className="h-5 w-5 text-orange-500" />
        </div>
        <div>
          <h3 className="font-semibold">Agent Safety Controls</h3>
          <p className="text-sm text-muted-foreground">
            Prevent runaway costs and dangerous actions
          </p>
        </div>
      </div>

      {/* Timeout */}
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
              <Label className="font-medium">Agent Timeout</Label>
              <InfoTooltip>
                Automatically stop agent loop if it exceeds this time limit
              </InfoTooltip>
            </div>
            <span className="text-sm font-mono bg-muted px-2 py-1 rounded">
              {settings.agent_timeout_seconds} sec
            </span>
          </div>
          <Slider
            min={30}
            max={300}
            step={10}
            value={[settings.agent_timeout_seconds]}
            onValueChange={(value) => onChange('agent_timeout_seconds', value[0])}
            className="cursor-pointer"
          />
          <div className="flex justify-between text-xs text-muted-foreground">
            <span>30 sec</span>
            <span>5 min</span>
          </div>
        </CardContent>
      </Card>

      {/* Cost Limit */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex items-center gap-2">
            <DollarSign className="h-4 w-4 text-muted-foreground" />
            <CardTitle className="text-base">Cost Limit</CardTitle>
          </div>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Label className="font-medium">Max Cost per Request</Label>
              <InfoTooltip>
                Stop agent if cost exceeds this limit (USD)
              </InfoTooltip>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-muted-foreground">$</span>
            <Input
              type="number"
              step="0.01"
              min="0.01"
              max="10"
              placeholder="0.50"
              value={settings.agent_max_cost_per_request ?? ''}
              onChange={(e) => {
                const val = e.target.value ? parseFloat(e.target.value) : null;
                onChange('agent_max_cost_per_request', val);
              }}
              className="w-32"
            />
            <span className="text-sm text-muted-foreground">per request</span>
          </div>
          <p className="text-xs text-muted-foreground">
            Leave empty = unlimited (Warning: may cause runaway costs)
          </p>
        </CardContent>
      </Card>

      {/* HITL */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <HandMetal className="h-4 w-4 text-muted-foreground" />
              <CardTitle className="text-base">Human-in-the-Loop (HITL)</CardTitle>
            </div>
            <Switch
              checked={settings.hitl_enabled}
              onCheckedChange={(checked) => onChange('hitl_enabled', checked)}
            />
          </div>
          <p className="text-sm text-muted-foreground">
            Require human approval before executing dangerous actions
          </p>
        </CardHeader>

        <Collapsible open={settings.hitl_enabled}>
          <CollapsibleContent>
            <CardContent className="pt-0 space-y-3 border-t">
              <div className="pt-3">
                <Label className="font-medium mb-3 block">
                  Select actions that require approval:
                </Label>
                <div className="space-y-2">
                  {DANGEROUS_ACTIONS.map((action) => (
                    <div
                      key={action.id}
                      className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted/50 transition-colors"
                    >
                      <div className="flex items-center gap-3">
                        <AlertTriangle className="h-4 w-4 text-amber-500" />
                        <div>
                          <span className="font-medium">{action.label}</span>
                          <p className="text-xs text-muted-foreground">
                            {action.description}
                          </p>
                        </div>
                      </div>
                      <Switch
                        checked={(settings.hitl_dangerous_actions || []).includes(action.id)}
                        onCheckedChange={(checked) =>
                          handleDangerousActionToggle(action.id, checked)
                        }
                      />
                    </div>
                  ))}
                </div>
              </div>

              {/* Info Box */}
              <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-lg p-3 text-sm">
                <div className="flex gap-2">
                  <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                  <p className="text-amber-800 dark:text-amber-200">
                    When agent needs to perform selected actions, the system will send a notification
                    and wait for your approval (timeout 60 seconds)
                  </p>
                </div>
              </div>
            </CardContent>
          </CollapsibleContent>
        </Collapsible>
      </Card>
    </div>
  );
}
