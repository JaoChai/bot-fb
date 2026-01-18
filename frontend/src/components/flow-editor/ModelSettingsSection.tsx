/**
 * ModelSettingsSection - AI model configuration
 * Part of 006-bots-refactor feature (T045)
 *
 * Extracts: primary_chat_model, fallback_chat_model, decision_model,
 *           fallback_decision_model, temperature slider, max_tokens
 */

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Brain, Eye } from 'lucide-react';
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
    supports_vision: true,
  },
  {
    id: 'openai/gpt-4o-mini',
    name: 'GPT-4o Mini',
    provider: 'OpenAI',
    context_length: 128000,
    pricing_prompt: 0.15,
    pricing_completion: 0.6,
    supports_vision: true,
  },
  {
    id: 'openai/gpt-4o',
    name: 'GPT-4o',
    provider: 'OpenAI',
    context_length: 128000,
    pricing_prompt: 2.5,
    pricing_completion: 10,
    supports_vision: true,
  },
  {
    id: 'anthropic/claude-3.5-sonnet',
    name: 'Claude 3.5 Sonnet',
    provider: 'Anthropic',
    context_length: 200000,
    pricing_prompt: 3,
    pricing_completion: 15,
    supports_vision: true,
  },
  // Reasoning Models (OpenRouter Best Practice)
  {
    id: 'openai/o1',
    name: 'OpenAI o1',
    provider: 'OpenAI',
    context_length: 200000,
    pricing_prompt: 15,
    pricing_completion: 60,
    supports_reasoning: true,
  },
  {
    id: 'openai/o1-mini',
    name: 'OpenAI o1-mini',
    provider: 'OpenAI',
    context_length: 128000,
    pricing_prompt: 3,
    pricing_completion: 12,
    supports_reasoning: true,
  },
  {
    id: 'deepseek/deepseek-r1',
    name: 'DeepSeek R1',
    provider: 'DeepSeek',
    context_length: 64000,
    pricing_prompt: 0.55,
    pricing_completion: 2.19,
    supports_reasoning: true,
  },
];

// Helper component to render model option with badges
function ModelOptionLabel({ model }: { model: ModelOption }) {
  return (
    <div className="flex items-center gap-2">
      <span>{model.name}</span>
      <span className="text-xs text-muted-foreground">({model.provider})</span>
      {model.supports_reasoning && (
        <Badge variant="secondary" className="h-5 px-1.5 text-[10px] gap-0.5">
          <Brain className="h-3 w-3" />
          Reasoning
        </Badge>
      )}
      {model.supports_vision && !model.supports_reasoning && (
        <Badge variant="outline" className="h-5 px-1.5 text-[10px] gap-0.5">
          <Eye className="h-3 w-3" />
          Vision
        </Badge>
      )}
    </div>
  );
}

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
                <ModelOptionLabel model={model} />
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
                <ModelOptionLabel model={model} />
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
                <ModelOptionLabel model={model} />
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
                <ModelOptionLabel model={model} />
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
