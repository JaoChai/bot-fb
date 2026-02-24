import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface ModelSelectorProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}

export function ModelSelector({ label, value, onChange, placeholder }: ModelSelectorProps) {
  return (
    <div className="space-y-2">
      <Label className="text-sm text-muted-foreground">{label}</Label>
      <Input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder || 'provider/model-name (เช่น openai/gpt-4o-mini)'}
        className="font-mono text-sm"
      />
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
    <div className="space-y-4">
      {/* Chat Models Row */}
      <div className="grid grid-cols-2 gap-4">
        <ModelSelector
          label="LLM Model สำหรับสนทนา"
          value={primaryModel}
          onChange={onPrimaryChange}
        />
        <ModelSelector
          label="โมเดลสำรอง (fallback)"
          value={fallbackModel}
          onChange={onFallbackChange}
        />
      </div>

      {/* Decision Models Row - Only show when enabled */}
      {showDecisionModels && (
        <div className="grid grid-cols-2 gap-4">
          <ModelSelector
            label="LLM Model สำหรับตัดสินใจ"
            value={decisionModel}
            onChange={onDecisionChange}
          />
          <ModelSelector
            label="โมเดลตัดสินใจสำรอง (fallback)"
            value={fallbackDecisionModel}
            onChange={onFallbackDecisionChange}
          />
        </div>
      )}
    </div>
  );
}
