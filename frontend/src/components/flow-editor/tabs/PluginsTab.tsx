import { Code, Puzzle } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { SettingSection } from '@/components/connections';
import { PluginSection } from '@/components/flow/PluginSection';
import { useToast } from '@/hooks/use-toast';

interface PluginsTabProps {
  botId: number;
  flowId: number | null;
  externalDataSources: string;
  onExternalDataSourcesChange: (value: string) => void;
}

function validateExternalDataSource(
  url: string,
  toast: ReturnType<typeof useToast>['toast']
): boolean {
  if (!url.trim()) return true;

  try {
    const parsed = new URL(url);
    if (parsed.protocol !== 'https:') {
      toast({
        title: 'Invalid URL',
        description: 'Only HTTPS URLs are allowed',
        variant: 'destructive',
      });
      return false;
    }
    if (['localhost', '127.0.0.1', '0.0.0.0'].includes(parsed.hostname)) {
      toast({
        title: 'Invalid URL',
        description: 'Internal URLs are not allowed',
        variant: 'destructive',
      });
      return false;
    }
    return true;
  } catch {
    toast({
      title: 'Invalid URL',
      description: 'Please enter a valid URL',
      variant: 'destructive',
    });
    return false;
  }
}

export function PluginsTab({
  botId,
  flowId,
  externalDataSources,
  onExternalDataSourcesChange,
}: PluginsTabProps) {
  const { toast } = useToast();

  const handleBlur = () => {
    validateExternalDataSource(externalDataSources, toast);
  };

  return (
    <div className="space-y-6">
      {/* External Data Sources */}
      <div className="border rounded-lg p-5 space-y-4">
        <SettingSection
          icon={Code}
          title="External Data Sources"
          description="เชื่อมต่อแหล่งข้อมูลภายนอกเพื่อให้ AI สามารถเรียกใช้ข้อมูลแบบ Real-time"
        >
          <Input
            placeholder="ค้นหาหรือใส่ URL ของ API endpoint..."
            value={externalDataSources}
            onChange={(e) => onExternalDataSourcesChange(e.target.value)}
            onBlur={handleBlur}
          />
          <p className="text-xs text-muted-foreground mt-2">
            • JSON API endpoints ต่าง ๆ สามารถใช้ได้ (HTTPS only)
            <br />• ใช้ <code>{'{data}'}</code> syntax ในคำสั่ง AI เพื่อเรียกใช้ข้อมูล
          </p>
        </SettingSection>
      </div>

      {/* Plugins */}
      <div className="border rounded-lg p-5 space-y-4">
        <SettingSection
          icon={Puzzle}
          title="Plugins"
          description="เพิ่มฟังก์ชันเพิ่มเติมให้ AI ผ่าน plugins"
        >
          <PluginSection botId={String(botId)} flowId={flowId} />
        </SettingSection>
      </div>
    </div>
  );
}
