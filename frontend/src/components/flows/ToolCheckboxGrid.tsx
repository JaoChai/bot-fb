import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';

const TOOLS = [
  { id: 'search_kb', emoji: '🔍', label: 'ค้นหาฐานความรู้', desc: 'ค้นหาข้อมูลจาก KB ที่เชื่อมต่อ', category: 'Knowledge' },
  { id: 'calculate', emoji: '🧮', label: 'คำนวณ', desc: 'คำนวณตัวเลข ราคา เปอร์เซ็นต์', category: 'Math' },
  { id: 'think', emoji: '🧠', label: 'คิดก่อนตอบ', desc: 'ให้ AI หยุดคิด วิเคราะห์ก่อนตอบ', category: 'Reasoning' },
  { id: 'get_current_datetime', emoji: '🕐', label: 'วันที่/เวลา', desc: 'บอกวันที่ เวลา วันในสัปดาห์ปัจจุบัน', category: 'Utility' },
  { id: 'escalate_to_human', emoji: '👤', label: 'ส่งต่อพนักงาน', desc: 'ส่งต่อบทสนทนาให้พนักงานจริงเมื่อ AI ช่วยไม่ได้', category: 'Handoff' },
] as const;

interface ToolCheckboxGridProps {
  enabledTools: string[];
  onChange: (tools: string[]) => void;
}

export function ToolCheckboxGrid({ enabledTools, onChange }: ToolCheckboxGridProps) {
  const toggleTool = (toolId: string, checked: boolean) => {
    if (checked) {
      onChange([...enabledTools, toolId]);
    } else {
      onChange(enabledTools.filter(t => t !== toolId));
    }
  };

  return (
    <div className="space-y-2">
      {TOOLS.map(tool => (
        <label
          key={tool.id}
          className="flex items-start gap-3 rounded-md border bg-card px-3 py-2.5 cursor-pointer transition-colors hover:bg-muted/40 has-[:checked]:border-primary/50 has-[:checked]:bg-primary/5"
        >
          <Checkbox
            checked={enabledTools.includes(tool.id)}
            onCheckedChange={(checked) => toggleTool(tool.id, !!checked)}
            className="mt-0.5"
          />
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium">{tool.label}</span>
              {tool.category && (
                <Badge variant="outline" className="text-[10px] h-4 px-1.5">{tool.category}</Badge>
              )}
            </div>
            {tool.desc && (
              <p className="mt-0.5 text-xs text-muted-foreground leading-relaxed">{tool.desc}</p>
            )}
          </div>
        </label>
      ))}
    </div>
  );
}
