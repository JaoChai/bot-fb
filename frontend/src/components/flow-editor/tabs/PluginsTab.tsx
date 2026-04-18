import { useMemo } from 'react';
import { Code, Puzzle, CheckCircle2, AlertCircle } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Panel } from '@/components/common';
import { PluginSection } from '@/components/flow/PluginSection';

interface PluginsTabProps {
  botId: number;
  flowId: number | null;
  externalDataSources: string;
  onExternalDataSourcesChange: (value: string) => void;
}

export function PluginsTab({
  botId,
  flowId,
  externalDataSources,
  onExternalDataSourcesChange,
}: PluginsTabProps) {
  const urlValidation = useMemo(() => {
    const url = externalDataSources.trim();
    if (!url) return { status: 'empty' as const };

    try {
      const parsed = new URL(url);
      if (!['http:', 'https:'].includes(parsed.protocol)) {
        return { status: 'invalid' as const, reason: 'รองรับเฉพาะ http:// และ https://' };
      }
      if (['localhost', '127.0.0.1', '0.0.0.0'].includes(parsed.hostname)) {
        return { status: 'invalid' as const, reason: 'ไม่อนุญาต URL ภายใน (localhost)' };
      }
      return { status: 'valid' as const };
    } catch {
      return { status: 'invalid' as const, reason: 'รูปแบบ URL ไม่ถูกต้อง' };
    }
  }, [externalDataSources]);

  return (
    <div className="space-y-6">
      {/* External Data Sources */}
      <Panel
        icon={Code}
        title="External Data Sources"
        description="เชื่อมต่อแหล่งข้อมูลภายนอกเพื่อให้ AI สามารถเรียกใช้ข้อมูลแบบ Real-time"
      >
        <Input
          placeholder="ค้นหาหรือใส่ URL ของ API endpoint..."
          value={externalDataSources}
          onChange={(e) => onExternalDataSourcesChange(e.target.value)}
        />
        {urlValidation.status === 'empty' && (
          <p className="text-xs text-muted-foreground mt-1">
            ใส่ URL ของ API endpoint (เช่น https://example.com/api/data) — HTTPS only
          </p>
        )}
        {urlValidation.status === 'valid' && (
          <p className="text-xs text-emerald-600 dark:text-emerald-400 mt-1 inline-flex items-center gap-1">
            <CheckCircle2 className="h-3 w-3" strokeWidth={1.75} />
            URL ถูกต้อง
          </p>
        )}
        {urlValidation.status === 'invalid' && (
          <p className="text-xs text-destructive mt-1 inline-flex items-center gap-1">
            <AlertCircle className="h-3 w-3" strokeWidth={1.75} />
            {urlValidation.reason}
          </p>
        )}
        <p className="text-xs text-muted-foreground mt-2">
          • ใช้ <code>{'{data}'}</code> syntax ในคำสั่ง AI เพื่อเรียกใช้ข้อมูล
        </p>
      </Panel>

      {/* Plugins */}
      <Panel
        icon={Puzzle}
        title="Plugins"
        description="เพิ่มฟังก์ชันเพิ่มเติมให้ AI ผ่าน plugins"
      >
        <PluginSection botId={String(botId)} flowId={flowId} />
      </Panel>
    </div>
  );
}
