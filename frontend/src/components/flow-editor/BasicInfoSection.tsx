/**
 * BasicInfoSection - Flow basic information fields
 * Part of 006-bots-refactor feature (T044)
 *
 * Extracts: name, is_default toggle, system_prompt textarea
 */

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { type FlowSectionProps } from './types';

export function BasicInfoSection({ formData, onChange, disabled }: FlowSectionProps) {
  return (
    <div className="space-y-4">
      <h3 className="text-lg font-medium">Basic Info</h3>

      {/* Flow Name */}
      <div className="space-y-2">
        <Label htmlFor="flow-name">Flow Name</Label>
        <Input
          id="flow-name"
          placeholder="Enter flow name"
          value={formData.name}
          onChange={(e) => onChange('name', e.target.value)}
          disabled={disabled || formData.is_default}
          className="w-full"
        />
        {formData.is_default && (
          <p className="text-xs text-muted-foreground">
            Base Flow name cannot be changed
          </p>
        )}
      </div>

      {/* Is Default Toggle */}
      <div className="flex items-center gap-3 p-4 border rounded-lg">
        <Switch
          id="is_default"
          checked={formData.is_default}
          onCheckedChange={(checked) => onChange('is_default', checked)}
          disabled={disabled}
        />
        <div className="flex-1">
          <Label htmlFor="is_default" className="cursor-pointer">
            Set as Default Flow
          </Label>
          <p className="text-xs text-muted-foreground mt-1">
            Default flow is used when no other flow matches the conversation context
          </p>
        </div>
      </div>

      {/* System Prompt */}
      <div className="space-y-2">
        <Label htmlFor="system-prompt">System Prompt</Label>
        <Textarea
          id="system-prompt"
          placeholder="You are a helpful assistant..."
          className="min-h-[200px] max-h-[400px] overflow-y-auto font-mono text-sm resize-y"
          value={formData.system_prompt ?? ''}
          onChange={(e) => onChange('system_prompt', e.target.value)}
          disabled={disabled}
        />
        <div className="flex justify-end gap-4 text-xs text-muted-foreground">
          <span>
            lines: {(formData.system_prompt ?? '').split('\n').length}
          </span>
          <span>
            words: {(formData.system_prompt ?? '').split(/\s+/).filter(Boolean).length}
          </span>
        </div>
      </div>
    </div>
  );
}
