import { memo, useMemo, useCallback } from 'react';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import {
  Plus,
  Link2,
  Settings,
  ArrowLeft,
  Bot,
  Loader2,
} from 'lucide-react';

interface FlowListItem {
  id: number;
  name: string;
  is_default: boolean;
}

interface FlowsListProps {
  flows: FlowListItem[];
  isLoading: boolean;
  selectedFlowId: number | null;
  botId: number;
}

// Memoized flow item to prevent re-renders
const FlowListItem = memo(function FlowListItem({
  flow,
  isSelected,
  onClick,
}: {
  flow: FlowListItem;
  isSelected: boolean;
  onClick: () => void;
}) {
  return (
    <button
      onClick={onClick}
      className={`w-full text-left px-3 py-2.5 rounded-lg text-sm transition-all duration-200 cursor-pointer
        ${flow.is_default ? 'border-l-4 border-l-primary' : 'border-l-4 border-l-transparent'}
        ${isSelected
          ? 'bg-primary/10 text-primary font-medium'
          : 'hover:bg-muted'
        }`}
    >
      <div className="flex items-center gap-2">
        {flow.is_default && (
          <svg className="h-4 w-4 text-primary shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v14l-5-2.5L5 18V4z" />
          </svg>
        )}
        <span className="truncate font-medium">{flow.name}</span>
      </div>
      {flow.is_default && (
        <div className="mt-1 ml-6">
          <span className="text-xs text-primary font-medium">Base Flow</span>
        </div>
      )}
    </button>
  );
});

export const FlowsList = memo(function FlowsList({
  flows,
  isLoading,
  selectedFlowId,
  botId,
}: FlowsListProps) {
  const navigate = useNavigate();

  // Memoize sorted flows - default flow first
  const sortedFlows = useMemo(() =>
    [...flows].sort((a, b) => {
      if (a.is_default && !b.is_default) return -1;
      if (!a.is_default && b.is_default) return 1;
      return 0;
    }),
    [flows]
  );

  // Memoize navigation handlers
  const handleCreateNew = useCallback(() => {
    navigate(`/flows/new?botId=${botId}`);
  }, [navigate, botId]);

  const handleFlowClick = useCallback((flowId: number) => {
    navigate(`/flows/${flowId}/edit?botId=${botId}`);
  }, [navigate, botId]);

  const handleEditConnection = useCallback(() => {
    navigate(`/bots/${botId}/edit`);
  }, [navigate, botId]);

  const handleBotSettings = useCallback(() => {
    navigate(`/bots/${botId}/settings`);
  }, [navigate, botId]);

  const handleBackToBots = useCallback(() => {
    navigate('/bots');
  }, [navigate]);

  return (
    <div className="w-52 border-r bg-card flex flex-col">
      {/* Logo */}
      <div className="h-14 flex items-center px-4 border-b">
        <span className="font-bold text-lg text-primary">BotFacebook</span>
      </div>

      {/* Create New Flow Button */}
      <div className="p-3">
        <Button
          className="w-full"
          variant="cta"
          onClick={handleCreateNew}
        >
          <Plus className="h-4 w-4 mr-2" />
          สร้างโฟลว์ใหม่
        </Button>
      </div>

      {/* Flow List */}
      <div className="flex-1 overflow-y-auto">
        {isLoading ? (
          <div className="flex justify-center py-4">
            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
          </div>
        ) : flows.length === 0 ? (
          <p className="text-sm text-muted-foreground text-center py-4">ยังไม่มี Flow</p>
        ) : (
          <div className="space-y-1 px-2">
            {sortedFlows.map((flow) => (
              <FlowListItem
                key={flow.id}
                flow={flow}
                isSelected={selectedFlowId === flow.id}
                onClick={() => handleFlowClick(flow.id)}
              />
            ))}
          </div>
        )}
      </div>

      {/* Bottom Action Buttons */}
      <div className="p-3 border-t space-y-2">
        <Button variant="cta-outline" size="sm" className="w-full justify-start">
          <Link2 className="h-4 w-4 mr-2" />
          Link ภายใน
        </Button>
        <Button
          variant="outline"
          size="sm"
          className="w-full justify-start"
          onClick={handleEditConnection}
        >
          <Settings className="h-4 w-4 mr-2" />
          แก้ไขการเชื่อมต่อ
        </Button>
        <Button
          variant="outline"
          size="sm"
          className="w-full justify-start"
          onClick={handleBotSettings}
        >
          <Bot className="h-4 w-4 mr-2" />
          ตั้งค่า Bot
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="w-full justify-start text-primary hover:text-primary"
          onClick={handleBackToBots}
        >
          <ArrowLeft className="h-4 w-4 mr-2" />
          กลับไปหน้าการเชื่อมต่อ
        </Button>
      </div>
    </div>
  );
});
