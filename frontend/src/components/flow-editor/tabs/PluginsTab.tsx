import { Puzzle } from 'lucide-react';
import { Panel } from '@/components/common';
import { PluginSection } from '@/components/flow/PluginSection';

interface PluginsTabProps {
  botId: number;
  flowId: number | null;
}

export function PluginsTab({ botId, flowId }: PluginsTabProps) {
  return (
    <Panel
      icon={Puzzle}
      title="การแจ้งเตือน Telegram"
      description="ส่งการแจ้งเตือนไป Telegram เมื่อบอทตอบตามเงื่อนไขที่กำหนด"
    >
      <PluginSection botId={String(botId)} flowId={flowId} />
    </Panel>
  );
}
