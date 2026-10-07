import { Route } from 'lucide-react';
import { useDecisionsModels, FALLBACK_DECISIONS_MODEL } from '@/hooks/useDecisionsModels';
import type { ConnectionFormData } from '@/hooks/useConnectionForm';
import { Panel } from '@/components/common';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';

interface SupportRouterSectionProps {
  formData: ConnectionFormData;
  handleChange: <K extends keyof ConnectionFormData>(
    field: K,
    value: ConnectionFormData[K]
  ) => void;
}

const MODE_OPTIONS: { value: ConnectionFormData['support_router_mode']; label: string }[] = [
  { value: 'off', label: 'ปิด' },
  { value: 'shadow', label: 'ทดลองเก็บคะแนนอย่างเดียว' },
  { value: 'on', label: 'เปิดใช้งาน' },
];

export function SupportRouterSection({ formData, handleChange }: SupportRouterSectionProps) {
  const { data: decisionsModels, isError } = useDecisionsModels();

  // Hardcoded fallback keeps Luna Decisions selectable when the API is down
  // or returns no decisions models.
  const models =
    isError || !decisionsModels || decisionsModels.length === 0
      ? [FALLBACK_DECISIONS_MODEL]
      : decisionsModels;
  const selectedModel = formData.support_router_model || FALLBACK_DECISIONS_MODEL.model_id;
  const selectedModelData =
    models.find((model) => model.model_id === selectedModel) ?? FALLBACK_DECISIONS_MODEL;
  const showMessageError =
    formData.support_router_mode === 'on' && !formData.support_handover_message.trim();

  return (
    <Panel
      icon={Route}
      title="คัดแยกส่ง Support (AI)"
      description="ใช้โมเดลประเภท Decisions ตรวจข้อความลูกค้าก่อนบอทตอบ ถ้าเป็นปัญหาหลังการขายจะส่งต่อทีม Support ทันที"
    >
      <div className="space-y-4">
        <div className="space-y-2">
          <Label className="text-sm text-muted-foreground">โหมดทำงาน</Label>
          <Select
            value={formData.support_router_mode}
            onValueChange={(value) => {
              const mode = value as ConnectionFormData['support_router_mode'];
              // The select displays the Luna fallback while the saved value is
              // still empty; Radix fires no onValueChange when the user clicks
              // the already-displayed option, so persist the default here or
              // display and saved value diverge (review round 2 blocker).
              if (mode !== 'off' && !formData.support_router_model) {
                handleChange('support_router_model', FALLBACK_DECISIONS_MODEL.model_id);
              }
              handleChange('support_router_mode', mode);
            }}
          >
            <SelectTrigger aria-label="โหมดทำงาน" className="w-full max-w-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {MODE_OPTIONS.map((option) => (
                <SelectItem key={option.value} value={option.value}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            ทดลองเก็บคะแนน = ระบบประเมินทุกข้อความแต่ยังไม่ส่งต่อจริง เอาไว้ดูความแม่นก่อนเปิดใช้งาน
          </p>
        </div>

        {formData.support_router_mode !== 'off' && (
          <>
            <div className="space-y-2">
              <Label className="text-sm text-muted-foreground">โมเดล Decisions</Label>
              <Select
                value={selectedModel}
                onValueChange={(value) => handleChange('support_router_model', value)}
              >
                <SelectTrigger aria-label="โมเดล Decisions" className="w-full max-w-xs">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {models.map((model) => (
                    <SelectItem key={model.model_id} value={model.model_id}>
                      {model.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                {selectedModelData.pricing_prompt > 0
                  ? `$${selectedModelData.pricing_prompt.toFixed(2)} / 1M input tokens`
                  : '$0.10 / 1M input tokens'}
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="support_handover_message" className="text-sm text-muted-foreground">
                ข้อความแจ้งลูกค้าตอนส่งต่อทีม Support
              </Label>
              <Textarea
                id="support_handover_message"
                value={formData.support_handover_message}
                onChange={(e) => handleChange('support_handover_message', e.target.value)}
                aria-invalid={showMessageError}
                aria-describedby={
                  showMessageError ? 'support_handover_message_error' : undefined
                }
                className="min-h-24"
              />
              {showMessageError && (
                <p
                  id="support_handover_message_error"
                  role="alert"
                  className="text-xs text-destructive"
                >
                  กรุณากรอกข้อความแจ้งลูกค้าก่อนส่งต่อทีม Support
                </p>
              )}
              {formData.support_router_mode === 'on' && (
                <p className="text-xs text-muted-foreground">
                  ข้อความนี้ระบบจะส่งให้ลูกค้าพร้อมกับส่งต่อทีม Support (บังคับกรอก)
                </p>
              )}
            </div>
          </>
        )}
      </div>
    </Panel>
  );
}
