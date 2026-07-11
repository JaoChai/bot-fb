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

// Convenience component for the primary+fallback+utility model set
interface ModelConfigurationProps {
  primaryModel: string;
  fallbackModel: string;
  utilityModel: string;
  onPrimaryChange: (value: string) => void;
  onFallbackChange: (value: string) => void;
  onUtilityChange: (value: string) => void;
}

export function ModelConfiguration({
  primaryModel,
  fallbackModel,
  utilityModel,
  onPrimaryChange,
  onFallbackChange,
  onUtilityChange,
}: ModelConfigurationProps) {
  return (
    <div className="space-y-4">
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
      <div className="space-y-1">
        <ModelSelector
          label="โมเดลงานเบื้องหลัง (จำข้อมูลลูกค้า, trigger ปลั๊กอิน, ตามลูกค้าหาย)"
          value={utilityModel}
          onChange={onUtilityChange}
          placeholder="เว้นว่าง = ใช้โมเดลสำรอง"
        />
        <p className="text-xs text-muted-foreground">
          งานจิ๋วเบื้องหลังที่ไม่ใช่การตอบแชท — แนะนำโมเดลราคาถูก เว้นว่างจะใช้โมเดลสำรอง (ถ้าไม่มีใช้ตัวหลัก)
        </p>
      </div>
    </div>
  );
}
