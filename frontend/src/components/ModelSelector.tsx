import { useState, useEffect } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

// Preset model categories with popular models
const MODEL_PRESETS = {
  custom: {
    label: 'Custom LLM (เลือก LLM เอง)',
    models: [],
  },
  google: {
    label: 'Google Gemini',
    models: [
      { id: 'google/gemini-2.0-flash-exp:free', name: 'Gemini 2.0 Flash (Free)' },
      { id: 'google/gemini-flash-1.5', name: 'Gemini Flash 1.5' },
      { id: 'google/gemini-pro-1.5', name: 'Gemini Pro 1.5' },
    ],
  },
  openai: {
    label: 'OpenAI GPT',
    models: [
      { id: 'openai/gpt-4o-mini', name: 'GPT-4o Mini' },
      { id: 'openai/gpt-4o', name: 'GPT-4o' },
      { id: 'openai/gpt-4-turbo', name: 'GPT-4 Turbo' },
      { id: 'openai/o1-mini', name: 'o1 Mini' },
    ],
  },
  anthropic: {
    label: 'Anthropic Claude',
    models: [
      { id: 'anthropic/claude-3.5-sonnet', name: 'Claude 3.5 Sonnet' },
      { id: 'anthropic/claude-3-haiku', name: 'Claude 3 Haiku' },
      { id: 'anthropic/claude-3-opus', name: 'Claude 3 Opus' },
    ],
  },
  meta: {
    label: 'Meta Llama',
    models: [
      { id: 'meta-llama/llama-3.3-70b-instruct', name: 'Llama 3.3 70B' },
      { id: 'meta-llama/llama-3.1-8b-instruct', name: 'Llama 3.1 8B' },
    ],
  },
  deepseek: {
    label: 'DeepSeek',
    models: [
      { id: 'deepseek/deepseek-chat', name: 'DeepSeek Chat' },
      { id: 'deepseek/deepseek-r1', name: 'DeepSeek R1' },
    ],
  },
} as const;

type PresetKey = keyof typeof MODEL_PRESETS;

interface ModelSelectorProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}

// Detect which preset a model ID belongs to
function detectPreset(modelId: string): PresetKey {
  if (!modelId) return 'custom';

  for (const [key, preset] of Object.entries(MODEL_PRESETS)) {
    if (key === 'custom') continue;
    if (preset.models.some(m => m.id === modelId)) {
      return key as PresetKey;
    }
    // Also check by provider prefix
    if (modelId.startsWith(`${key}/`) || modelId.startsWith(`${key}-`)) {
      return key as PresetKey;
    }
  }

  // Check for provider prefix patterns
  if (modelId.startsWith('google/') || modelId.startsWith('gemini')) return 'google';
  if (modelId.startsWith('openai/') || modelId.startsWith('gpt')) return 'openai';
  if (modelId.startsWith('anthropic/') || modelId.startsWith('claude')) return 'anthropic';
  if (modelId.startsWith('meta-llama/') || modelId.startsWith('llama')) return 'meta';
  if (modelId.startsWith('deepseek/')) return 'deepseek';

  return 'custom';
}

export function ModelSelector({ label, value, onChange, placeholder }: ModelSelectorProps) {
  const [selectedPreset, setSelectedPreset] = useState<PresetKey>(() => detectPreset(value));
  const [customValue, setCustomValue] = useState(value || '');

  // Update custom value when value prop changes
  useEffect(() => {
    setCustomValue(value || '');
    setSelectedPreset(detectPreset(value));
  }, [value]);

  // Handle preset change
  const handlePresetChange = (preset: PresetKey) => {
    setSelectedPreset(preset);

    if (preset === 'custom') {
      // Keep current value for custom input
      return;
    }

    // Auto-select first model from preset
    const models = MODEL_PRESETS[preset].models;
    if (models.length > 0) {
      const firstModel = models[0].id;
      setCustomValue(firstModel);
      onChange(firstModel);
    }
  };

  // Handle custom value change
  const handleCustomChange = (newValue: string) => {
    setCustomValue(newValue);
    onChange(newValue);
  };

  // Handle specific model selection from preset
  const handleModelSelect = (modelId: string) => {
    setCustomValue(modelId);
    onChange(modelId);
  };

  const currentPreset = MODEL_PRESETS[selectedPreset];
  const hasModels = selectedPreset !== 'custom' && currentPreset.models.length > 0;

  return (
    <div className="space-y-2">
      <Label className="text-sm text-muted-foreground">{label}</Label>

      {/* Preset Selector */}
      <Select value={selectedPreset} onValueChange={(v) => handlePresetChange(v as PresetKey)}>
        <SelectTrigger className="w-full">
          <SelectValue placeholder="เลือกประเภท LLM" />
        </SelectTrigger>
        <SelectContent>
          {Object.entries(MODEL_PRESETS).map(([key, preset]) => (
            <SelectItem key={key} value={key}>
              {preset.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      {/* Model Input/Selection */}
      {hasModels ? (
        // Show model dropdown for presets
        <Select value={customValue} onValueChange={handleModelSelect}>
          <SelectTrigger className="w-full">
            <SelectValue placeholder="เลือกโมเดล" />
          </SelectTrigger>
          <SelectContent>
            {currentPreset.models.map((model) => (
              <SelectItem key={model.id} value={model.id}>
                {model.name}
              </SelectItem>
            ))}
            <SelectItem value="__custom__">อื่นๆ (กรอกเอง)</SelectItem>
          </SelectContent>
        </Select>
      ) : null}

      {/* Custom Input - Always visible for Custom or when custom is selected from preset */}
      {(selectedPreset === 'custom' || customValue === '__custom__' || !hasModels || !currentPreset.models.some(m => m.id === customValue)) && (
        <Input
          value={customValue === '__custom__' ? '' : customValue}
          onChange={(e) => handleCustomChange(e.target.value)}
          placeholder={placeholder || 'provider/model-name (เช่น openai/gpt-4o-mini)'}
          className="font-mono text-sm"
        />
      )}
    </div>
  );
}

// Convenience component for 4-model configuration
interface ModelConfigurationProps {
  primaryModel: string;
  fallbackModel: string;
  decisionModel: string;
  fallbackDecisionModel: string;
  onPrimaryChange: (value: string) => void;
  onFallbackChange: (value: string) => void;
  onDecisionChange: (value: string) => void;
  onFallbackDecisionChange: (value: string) => void;
  showDecisionModels?: boolean;
}

export function ModelConfiguration({
  primaryModel,
  fallbackModel,
  decisionModel,
  fallbackDecisionModel,
  onPrimaryChange,
  onFallbackChange,
  onDecisionChange,
  onFallbackDecisionChange,
  showDecisionModels = false,
}: ModelConfigurationProps) {
  return (
    <div className="space-y-6">
      {/* Chat Models Row */}
      <div className="grid grid-cols-2 gap-4">
        <ModelSelector
          label="LLM Model ที่ต้องการใช้ในการสนทนา & Personality"
          value={primaryModel}
          onChange={onPrimaryChange}
        />
        <ModelSelector
          label="โมเดลสำรอง ที่ใช้ในการสนทนา (fallback)"
          value={fallbackModel}
          onChange={onFallbackChange}
        />
      </div>

      {/* Decision Models Row - Only show when Agentic Mode is enabled */}
      {showDecisionModels && (
        <div className="grid grid-cols-2 gap-4">
          <ModelSelector
            label="LLM Model สำหรับการตัดสินใจ"
            value={decisionModel}
            onChange={onDecisionChange}
          />
          <ModelSelector
            label="โมเดลสำหรับการตัดสินใจ (fallback)"
            value={fallbackDecisionModel}
            onChange={onFallbackDecisionChange}
          />
        </div>
      )}
    </div>
  );
}
