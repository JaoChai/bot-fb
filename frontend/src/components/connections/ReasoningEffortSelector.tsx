import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type Effort = 'low' | 'medium' | 'high';

interface ReasoningEffortSelectorProps {
  value: Effort;
  onChange: (value: Effort) => void;
}

const OPTIONS: { value: Effort; title: string; hint: string }[] = [
  { value: 'low', title: 'Low', hint: 'เร็วสุด · ประหยัด' },
  { value: 'medium', title: 'Medium', hint: 'สมดุล (แนะนำ)' },
  { value: 'high', title: 'High', hint: 'ฉลาดสุด · ช้ากว่า · แพงกว่า' },
];

export function ReasoningEffortSelector({ value, onChange }: ReasoningEffortSelectorProps) {
  return (
    <div className="space-y-2">
      <Label className="text-sm text-muted-foreground">Reasoning Effort</Label>
      <div className="flex flex-col gap-2" role="radiogroup" aria-label="Reasoning Effort">
        {OPTIONS.map((opt) => (
          <button
            key={opt.value}
            type="button"
            role="radio"
            aria-checked={value === opt.value}
            onClick={() => onChange(opt.value)}
            className={cn(
              'flex items-center justify-between rounded-md border px-3 py-2 text-sm text-left transition-colors',
              value === opt.value
                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                : 'border-input hover:bg-muted/50',
            )}
          >
            <span className="font-medium">{opt.title}</span>
            <span className="text-xs text-muted-foreground">{opt.hint}</span>
          </button>
        ))}
      </div>
      <p className="text-xs text-muted-foreground">
        มีผลเฉพาะโมเดลที่รองรับ reasoning (เช่น o1, gpt-5) — โมเดลอื่นระบบข้ามให้อัตโนมัติ
        และข้อความง่ายระบบจะลดระดับให้เองเพื่อความเร็ว
      </p>
    </div>
  );
}
