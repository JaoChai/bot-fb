import { useState } from 'react';
import { Bot, Sparkles, Star, AlertCircle, Brain, FileText, ChevronDown, HelpCircle } from 'lucide-react';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SettingSection, SettingRow } from '@/components/connections';
import { ToolCheckboxGrid } from '@/components/flows/ToolCheckboxGrid';
import { cn } from '@/lib/utils';

interface AgentTabProps {
  agenticMode: boolean;
  enabledTools: string[];
  maxToolCalls: number;
  maxTokens: number;
  isDefault: boolean;
  onChange: (
    field: 'agentic_mode' | 'enabled_tools' | 'max_tool_calls' | 'max_tokens' | 'is_default',
    value: unknown
  ) => void;
}

const SMART_DEFAULTS = ['search_kb', 'escalate_to_human'];

export function AgentTab({
  agenticMode,
  enabledTools,
  maxToolCalls,
  maxTokens,
  isDefault,
  onChange,
}: AgentTabProps) {
  const [helpOpen, setHelpOpen] = useState(false);

  const handleAgenticToggle = (enabled: boolean) => {
    onChange('agentic_mode', enabled);
    if (enabled && (!enabledTools || enabledTools.length === 0)) {
      onChange('enabled_tools', SMART_DEFAULTS);
    }
  };

  return (
    <div className="space-y-6">
      {/* Agentic Mode */}
      <div className="border rounded-lg p-5 space-y-4">
        {/* Help Block */}
        <Collapsible open={helpOpen} onOpenChange={setHelpOpen} className="rounded-md border bg-muted/30">
          <CollapsibleTrigger className="flex w-full items-center justify-between gap-2 px-3 py-2 text-sm font-medium hover:bg-muted/50 transition-colors">
            <span className="flex items-center gap-2">
              <HelpCircle className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
              Agentic Mode คืออะไร?
            </span>
            <ChevronDown className={cn('h-4 w-4 text-muted-foreground transition-transform', helpOpen && 'rotate-180')} strokeWidth={1.5} />
          </CollapsibleTrigger>
          <CollapsibleContent>
            <div className="border-t px-3 py-3 text-xs text-muted-foreground space-y-2 leading-relaxed">
              <p>
                <span className="font-medium text-foreground">Agentic Mode</span> ให้บอทสามารถเรียกใช้ "เครื่องมือ" (tools) เพื่อทำงานซับซ้อนได้ เช่น ค้นหาในฐานความรู้ ส่งต่อให้แอดมิน หรือเรียก API ภายนอก
              </p>
              <p>
                แทนที่จะตอบจากความรู้ทั่วไปอย่างเดียว บอทจะตัดสินใจเรียก tool ที่เหมาะสม รับผลลัพธ์ แล้วนำมาประกอบคำตอบ
              </p>
              <p className="text-foreground font-medium">คำแนะนำเริ่มต้น:</p>
              <ul className="list-disc list-inside space-y-0.5 pl-2">
                <li><code className="text-[11px] bg-background px-1 py-0.5 rounded border">escalate_to_human</code> — ส่งต่อแอดมินเมื่อบอทตอบไม่ได้</li>
                <li><code className="text-[11px] bg-background px-1 py-0.5 rounded border">search_kb</code> — ค้นหาในฐานความรู้ก่อนตอบ</li>
              </ul>
              <p className="text-[11px] text-muted-foreground">* ถ้าเลือกเครื่องมือที่ไม่เหมาะกับงาน บอทอาจเรียกใช้ผิดหรือเรียกซ้ำ เพิ่มค่าใช้จ่าย</p>
            </div>
          </CollapsibleContent>
        </Collapsible>

        <SettingSection
          icon={Bot}
          title="Agentic Mode"
          description="เปลี่ยน AI ธรรมดาให้เป็น AI Agent ที่สามารถค้นหาข้อมูล เรียกใช้ tools และตัดสินใจได้อย่างอัตโนมัติ"
          action={<Badge variant="secondary">AI ที่ฉลาดขึ้น</Badge>}
        >
          <SettingRow label="เปิดใช้งาน Agentic Mode" htmlFor="agentic-mode-toggle">
            <Switch
              id="agentic-mode-toggle"
              checked={agenticMode}
              onCheckedChange={handleAgenticToggle}
            />
          </SettingRow>
        </SettingSection>

        {agenticMode && (
          <div className="space-y-4 border-t pt-4 sm:pl-12">
            {/* Tool Selection */}
            <div className="space-y-2">
              <Label className="text-sm font-medium">เลือก Tools ที่ AI สามารถใช้ได้</Label>
              <ToolCheckboxGrid
                enabledTools={enabledTools}
                onChange={(tools) => onChange('enabled_tools', tools)}
              />
              {enabledTools.length === 0 && (
                <div className="flex items-center gap-2 p-3 mt-2 rounded-lg border border-destructive/50 bg-destructive/5 text-destructive">
                  <AlertCircle className="h-4 w-4 flex-shrink-0" />
                  <p className="text-xs">
                    กรุณาเลือกอย่างน้อย 1 tool เพื่อใช้งาน Agentic Mode
                  </p>
                </div>
              )}
            </div>

            {/* Max Tool Calls */}
            <div className="flex items-center gap-4">
              <Label className="text-sm">จำนวนครั้งสูงสุดในการเรียกใช้ Tools</Label>
              <Input
                type="number"
                min={5}
                max={15}
                value={maxToolCalls}
                onChange={(e) => {
                  const val = parseInt(e.target.value, 10);
                  if (!isNaN(val)) onChange('max_tool_calls', val);
                }}
                className="w-20"
              />
              <span className="text-xs text-muted-foreground">
                5-15 ครั้ง • AI จะหยุดทำงานอัตโนมัติเมื่อถึงจำนวนนี้
              </span>
            </div>

            {/* Max Tokens */}
            <div className="flex items-center gap-4">
              <Label className="text-sm">Max Tokens (ความยาวคำตอบ)</Label>
              <Input
                type="number"
                min={512}
                max={8192}
                step={256}
                value={maxTokens}
                onChange={(e) => {
                  const val = parseInt(e.target.value, 10);
                  if (!isNaN(val)) onChange('max_tokens', val);
                }}
                className="w-24"
              />
              <span className="text-xs text-muted-foreground">
                512-8192 • กำหนดความยาวสูงสุดของคำตอบ AI
              </span>
            </div>
          </div>
        )}
      </div>

      {/* AI Enhancement Features */}
      <div className="border rounded-lg p-5 space-y-4">
        <SettingSection
          icon={Sparkles}
          title="AI Enhancement"
          description="ฟีเจอร์เหล่านี้ทำงานอัตโนมัติเพื่อให้ AI ตอบได้ดีขึ้น"
          action={<Badge variant="secondary" className="text-[10px]">Auto</Badge>}
        >
          <div className="space-y-3">
            {/* Chain-of-Thought */}
            <div className="flex items-start gap-3 p-3 bg-muted/40 border rounded-lg">
              <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-md border bg-muted/40 text-muted-foreground">
                <Brain className="h-4 w-4" />
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <span className="text-sm font-medium">Chain-of-Thought</span>
                  <Badge variant="secondary" className="text-[10px]">Auto</Badge>
                </div>
                <p className="text-xs text-muted-foreground mt-0.5">
                  เมื่อตรวจพบคำถามซับซ้อน AI จะวิเคราะห์ทีละขั้นตอนก่อนตอบ
                </p>
              </div>
            </div>

            {/* Contextual Retrieval */}
            <div className="flex items-start gap-3 p-3 bg-muted/40 border rounded-lg">
              <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-md border bg-muted/40 text-muted-foreground">
                <FileText className="h-4 w-4" />
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <span className="text-sm font-medium">Contextual Retrieval</span>
                  <Badge variant="secondary" className="text-[10px]">Auto</Badge>
                </div>
                <p className="text-xs text-muted-foreground mt-0.5">
                  Documents ใหม่จะมี context เพิ่ม ช่วยให้ค้นหาได้แม่นยำขึ้น 49%
                </p>
              </div>
            </div>
          </div>
        </SettingSection>
      </div>

      {/* Set as Default Flow */}
      <div className="border rounded-lg p-5">
        <SettingSection
          icon={Star}
          title="Flow เริ่มต้น"
          description="ใช้ Flow นี้เป็น Flow หลักของบอท"
        >
          <SettingRow label="ตั้งเป็น Flow เริ่มต้น" htmlFor="is-default-toggle">
            <Switch
              id="is-default-toggle"
              checked={isDefault}
              onCheckedChange={(checked) => onChange('is_default', checked)}
            />
          </SettingRow>
        </SettingSection>
      </div>
    </div>
  );
}
