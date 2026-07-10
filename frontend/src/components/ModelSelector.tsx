import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface ModelSelectorProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}

function ModelSelector({ label, value, onChange, placeholder }: ModelSelectorProps) {
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

// Convenience component for the single primary+fallback model pair
interface ModelConfigurationProps {
  primaryModel: string;
  fallbackModel: string;
  onPrimaryChange: (value: string) => void;
  onFallbackChange: (value: string) => void;
}

export function ModelConfiguration({
  primaryModel,
  fallbackModel,
  onPrimaryChange,
  onFallbackChange,
}: ModelConfigurationProps) {
  return (
    <div className="grid grid-cols-2 gap-4">
      <ModelSelector
        label="LLM Model หลัก"
        value={primaryModel}
        onChange={onPrimaryChange}
      />
      <ModelSelector
        label="โมเดลสำรอง (fallback)"
        value={fallbackModel}
        onChange={onFallbackChange}
      />
    </div>
  );
}
