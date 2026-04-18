import { Sliders } from 'lucide-react';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { SettingSection, SettingRow } from '@/components/connections';

interface ModelTabProps {
  temperature: number;
  maxTokens: number;
  language: string;
  onChange: (field: 'temperature' | 'max_tokens' | 'language', value: number | string) => void;
}

export function ModelTab({ temperature, maxTokens, language, onChange }: ModelTabProps) {
  return (
    <div className="border rounded-lg p-5 space-y-4">
      <SettingSection
        icon={Sliders}
        title="Model Parameters"
        description="ปรับค่าการตอบสนองของ AI"
      >
        {/* Temperature */}
        <div className="space-y-2">
          <Label className="text-sm font-medium">
            Temperature:{' '}
            <span className="font-semibold tabular-nums">{temperature}</span>
          </Label>
          <p className="text-xs text-muted-foreground">
            ต่ำ = ตอบตรงประเด็น, สูง = ตอบสร้างสรรค์
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
            ความยาวสูงสุดของคำตอบ AI
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

        {/* Language */}
        <SettingRow label="ภาษาการตอบ" htmlFor="model-language">
          <Select
            value={language}
            onValueChange={(val) => onChange('language', val)}
          >
            <SelectTrigger id="model-language" className="w-36">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="th">ไทย</SelectItem>
              <SelectItem value="en">English</SelectItem>
            </SelectContent>
          </Select>
        </SettingRow>
      </SettingSection>
    </div>
  );
}
