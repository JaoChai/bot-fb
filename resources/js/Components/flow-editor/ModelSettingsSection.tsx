/**
 * ModelSettingsSection - AI model configuration
 * Part of 006-bots-refactor feature (T045)
 *
 * Extracts: primary_chat_model, fallback_chat_model, decision_model,
 *           fallback_decision_model, temperature slider, max_tokens
 *
 * Copied from frontend and adapted for Inertia context
 */

import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Slider } from '@/Components/ui/slider';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import { type FlowSectionProps, type ModelOption } from './types';

// Common model options - can be moved to a shared config
const CHAT_MODEL_OPTIONS: ModelOption[] = [
  {
    id: 'google/gemini-2.0-flash-exp:free',
    name: 'Gemini 2.0 Flash (Free)',
    provider: 'Google',
    context_length: 1000000,
    pricing_prompt: 0,
    pricing_completion: 0,
  },
  {
    id: 'openai/gpt-4o-mini',
    name: 'GPT-4o Mini',
    provider: 'OpenAI',
    context_length: 128000,
    pricing_prompt: 0.15,
    pricing_completion: 0.6,
  },
  {
    id: 'openai/gpt-4o',
    name: 'GPT-4o',
    provider: 'OpenAI',
    context_length: 128000,
    pricing_prompt: 2.5,
    pricing_completion: 10,
  },
  {
    id: 'anthropic/claude-3.5-sonnet',
    name: 'Claude 3.5 Sonnet',
    provider: 'Anthropic',
    context_length: 200000,
    pricing_prompt: 3,
    pricing_completion: 15,
  },
];

interface ModelSettingsSectionProps extends FlowSectionProps {
  modelOptions?: ModelOption[];
}

export function ModelSettingsSection({
  formData,
  onChange,
  disabled,
  modelOptions = CHAT_MODEL_OPTIONS,
}: ModelSettingsSectionProps) {
  return (
    <div className="space-y-4">
      <h3 className="text-lg font-medium">Model Settings</h3>

      {/* Primary Chat Model */}
      <div className="space-y-2">
        <Label htmlFor="primary-chat-model">Primary Chat Model</Label>
        <Select
          value={formData.primary_chat_model ?? ''}
          onValueChange={(value) => onChange('primary_chat_model', value)}
          disabled={disabled}
        >
          <SelectTrigger id="primary-chat-model" className="w-full">
            <SelectValue placeholder="Select primary model" />
          </SelectTrigger>
          <SelectContent>
            {modelOptions.map((model) => (
              <SelectItem key={model.id} value={model.id}>
                <span>{model.name}</span>
                <span className="text-xs text-muted-foreground ml-2">
                  ({model.provider})
                </span>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <p className="text-xs text-muted-foreground">
          Main model used for generating responses
        </p>
      </div>

      {/* Fallback Chat Model */}
      <div className="space-y-2">
        <Label htmlFor="fallback-chat-model">Fallback Chat Model</Label>
        <Select
          value={formData.fallback_chat_model ?? ''}
          onValueChange={(value) => onChange('fallback_chat_model', value)}
          disabled={disabled}
        >
          <SelectTrigger id="fallback-chat-model" className="w-full">
            <SelectValue placeholder="Select fallback model" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="">None</SelectItem>
            {modelOptions.map((model) => (
              <SelectItem key={model.id} value={model.id}>
                <span>{model.name}</span>
                <span className="text-xs text-muted-foreground ml-2">
                  ({model.provider})
                </span>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <p className="text-xs text-muted-foreground">
          Used when primary model fails or is unavailable
        </p>
      </div>

      {/* Decision Model */}
      <div className="space-y-2">
        <Label htmlFor="decision-model">Decision Model</Label>
        <Select
          value={formData.decision_model ?? ''}
          onValueChange={(value) => onChange('decision_model', value)}
          disabled={disabled}
        >
          <SelectTrigger id="decision-model" className="w-full">
            <SelectValue placeholder="Select decision model" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="">Same as primary</SelectItem>
            {modelOptions.map((model) => (
              <SelectItem key={model.id} value={model.id}>
                <span>{model.name}</span>
                <span className="text-xs text-muted-foreground ml-2">
                  ({model.provider})
                </span>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <p className="text-xs text-muted-foreground">
          Model used for tool selection and routing decisions
        </p>
      </div>

      {/* Fallback Decision Model */}
      <div className="space-y-2">
        <Label htmlFor="fallback-decision-model">Fallback Decision Model</Label>
        <Select
          value={formData.fallback_decision_model ?? ''}
          onValueChange={(value) => onChange('fallback_decision_model', value)}
          disabled={disabled}
        >
          <SelectTrigger id="fallback-decision-model" className="w-full">
            <SelectValue placeholder="Select fallback decision model" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="">None</SelectItem>
            {modelOptions.map((model) => (
              <SelectItem key={model.id} value={model.id}>
                <span>{model.name}</span>
                <span className="text-xs text-muted-foreground ml-2">
                  ({model.provider})
                </span>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <p className="text-xs text-muted-foreground">
          Used when decision model fails
        </p>
      </div>

      {/* Temperature */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <Label>Temperature: {formData.temperature.toFixed(1)}</Label>
          <span className="text-xs text-muted-foreground">
            Low = focused, High = creative
          </span>
        </div>
        <Slider
          value={[formData.temperature]}
          onValueChange={([v]) => onChange('temperature', v)}
          min={0}
          max={1}
          step={0.1}
          disabled={disabled}
        />
      </div>

      {/* Max Tokens */}
      <div className="space-y-2">
        <Label htmlFor="max-tokens">Max Tokens</Label>
        <Input
          id="max-tokens"
          type="number"
          min={256}
          max={16384}
          value={formData.max_tokens ?? 2048}
          onChange={(e) => {
            const val = parseInt(e.target.value, 10);
            if (!isNaN(val)) onChange('max_tokens', val);
          }}
          disabled={disabled}
          className="w-32"
        />
        <p className="text-xs text-muted-foreground">
          Maximum length of generated responses (256-16384)
        </p>
      </div>
    </div>
  );
}
