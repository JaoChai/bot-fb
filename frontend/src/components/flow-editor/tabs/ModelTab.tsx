import { Sliders } from 'lucide-react';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Input } from '@/components/ui/input';
import { SettingSection } from '@/components/connections';
import { cn } from '@/lib/utils';

const PRESETS = [
  {
    id: 'precise',
    label: 'แม่นยำ',
    description: 'ตรงประเด็น ตอบสั้น เหมาะกับ FAQ / คำสั่ง',
    temperature: 0.3,
    maxTokens: 1024,
  },
  {
    id: 'balanced',
    label: 'สมดุล',
    description: 'เป็นธรรมชาติ เหมาะกับแชททั่วไป',
    temperature: 0.7,
    maxTokens: 2048,
  },
  {
    id: 'creative',
    label: 'สร้างสรรค์',
    description: 'คำตอบหลากหลาย เหมาะกับงานเขียน/ไอเดีย',
    temperature: 1.0,
    maxTokens: 4096,
  },
] as const;

interface ModelTabProps {
  temperature: number;
  maxTokens: number;
  onChange: (field: 'temperature' | 'max_tokens', value: number) => void;
}

export function ModelTab({ temperature, maxTokens, onChange }: ModelTabProps) {
  const activePreset = PRESETS.find(
    (p) => Math.abs(p.temperature - temperature) < 0.05 && p.maxTokens === maxTokens,
  )?.id;

  const applyPreset = (p: (typeof PRESETS)[number]) => {
    onChange('temperature', p.temperature);
    onChange('max_tokens', p.maxTokens);
  };

  return (
    <div className="border rounded-lg p-5 space-y-4">
      <SettingSection
        icon={Sliders}
        title="Model Parameters"
        description="ปรับค่าการตอบสนองของ AI"
      >
        {/* Presets */}
        <div className="space-y-3">
          <Label>Preset</Label>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
            {PRESETS.map((p) => {
              const isActive = activePreset === p.id;
              return (
                <button
                  key={p.id}
                  type="button"
                  onClick={() => applyPreset(p)}
                  className={cn(
                    'flex flex-col gap-1 rounded-md border bg-card p-3 text-left transition-colors',
                    isActive
                      ? 'border-primary bg-primary/5 ring-1 ring-primary/20'
                      : 'hover:bg-muted/40',
                  )}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-medium">{p.label}</span>
                    <span className="text-[10px] text-muted-foreground tabular-nums">
                      T={p.temperature} · {p.maxTokens}
                    </span>
                  </div>
                  <p className="text-xs text-muted-foreground leading-relaxed">{p.description}</p>
                </button>
              );
            })}
          </div>
        </div>

        {/* Temperature */}
        <div className="space-y-2">
          <Label className="text-sm font-medium">
            Temperature:{' '}
            <span className="font-semibold tabular-nums">{temperature}</span>
          </Label>
          <p className="text-xs text-muted-foreground">
            ต่ำ (0.0–0.5) = ตรงประเด็น, ตอบซ้ำคล้ายกัน · สูง (0.8–1.5) = คำตอบหลากหลาย สร้างสรรค์มากขึ้น
          </p>
          <Slider
            value={[temperature]}
            onValueChange={([v]) => onChange('temperature', v)}
            min={0}
            max={1}
            step={0.1}
          />
        </div>

        {/* Max Tokens */}
        <div className="space-y-2">
          <Label className="text-sm font-medium">
            Max Tokens:{' '}
            <span className="font-semibold tabular-nums">{maxTokens}</span>
          </Label>
          <p className="text-xs text-muted-foreground">
            จำกัดความยาวคำตอบของบอท — 1024 พอสำหรับตอบสั้น, 4096+ สำหรับตอบยาวมีรายละเอียด
          </p>
          <div className="flex items-center gap-3">
            <Slider
              value={[maxTokens]}
              onValueChange={([v]) => onChange('max_tokens', v)}
              min={512}
              max={16384}
              step={256}
              className="flex-1"
            />
            <Input
              type="number"
              min={512}
              max={16384}
              step={256}
              value={maxTokens}
              onChange={(e) => {
                const val = parseInt(e.target.value, 10);
                if (!isNaN(val)) onChange('max_tokens', val);
              }}
              className="w-24"
            />
          </div>
        </div>

      </SettingSection>
    </div>
  );
}
