import { cn } from '@/lib/utils';

const TOOLS = [
  { id: 'search_kb', emoji: '🔍', label: 'ค้นหาฐานความรู้', desc: 'ค้นหาข้อมูลจาก KB ที่เชื่อมต่อ' },
  { id: 'calculate', emoji: '🧮', label: 'คำนวณ', desc: 'คำนวณตัวเลข ราคา เปอร์เซ็นต์' },
  { id: 'think', emoji: '🧠', label: 'คิดก่อนตอบ', desc: 'ให้ AI หยุดคิด วิเคราะห์ก่อนตอบ' },
  { id: 'get_current_datetime', emoji: '🕐', label: 'วันที่/เวลา', desc: 'บอกวันที่ เวลา วันในสัปดาห์ปัจจุบัน' },
  { id: 'escalate_to_human', emoji: '👤', label: 'ส่งต่อพนักงาน', desc: 'ส่งต่อบทสนทนาให้พนักงานจริงเมื่อ AI ช่วยไม่ได้' },
] as const;

interface ToolCheckboxGridProps {
  enabledTools: string[];
  onChange: (tools: string[]) => void;
  compact?: boolean;
}

export function ToolCheckboxGrid({ enabledTools, onChange, compact }: ToolCheckboxGridProps) {
  const toggleTool = (toolId: string, checked: boolean) => {
    if (checked) {
      onChange([...enabledTools, toolId]);
    } else {
      onChange(enabledTools.filter(t => t !== toolId));
    }
  };

  return (
    <div className={compact ? 'space-y-2' : 'grid grid-cols-2 gap-2'}>
      {TOOLS.map(tool => (
        <label
          key={tool.id}
          className={cn(
            'flex items-center gap-2 p-3 rounded-lg border cursor-pointer transition-colors',
            enabledTools.includes(tool.id)
              ? 'bg-accent border-foreground'
              : 'hover:bg-muted'
          )}
        >
          <input
            type="checkbox"
            checked={enabledTools.includes(tool.id)}
            onChange={(e) => toggleTool(tool.id, e.target.checked)}
            className="rounded border-muted-foreground/50"
          />
          <div className="flex-1">
            <div className="flex items-center gap-1.5 text-sm font-medium">
              <span>{tool.emoji}</span>
              <span>{tool.label}</span>
            </div>
            {!compact && (
              <p className="text-xs text-muted-foreground">
                {tool.desc}
              </p>
            )}
          </div>
        </label>
      ))}
    </div>
  );
}
