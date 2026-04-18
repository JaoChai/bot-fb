import { memo, useMemo, useCallback } from 'react';
import { useNavigate } from 'react-router';
import { Plus, ArrowLeft, Loader2, Bookmark } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

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

const FlowItem = memo(function FlowItem({
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
      className={cn(
        'w-full text-left px-3 py-2 rounded-md text-sm transition-colors cursor-pointer',
        isSelected
          ? 'bg-muted text-foreground font-medium'
          : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
      )}
    >
      <div className="flex items-center gap-2 min-w-0">
        {flow.is_default && (
          <Bookmark className="h-3.5 w-3.5 shrink-0 fill-current" />
        )}
        <span className="truncate flex-1">{flow.name}</span>
        {flow.is_default && (
          <Badge variant="secondary" className="text-[10px] px-1 py-0 shrink-0">
            Base
          </Badge>
        )}
      </div>
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

  const sortedFlows = useMemo(() =>
    [...flows].sort((a, b) => {
      if (a.is_default && !b.is_default) return -1;
      if (!a.is_default && b.is_default) return 1;
      return 0;
    }),
    [flows]
  );

  const handleCreateNew = useCallback(() => {
    navigate(`/flows/new?botId=${botId}`);
  }, [navigate, botId]);

  const handleFlowClick = useCallback((flowId: number) => {
    navigate(`/flows/${flowId}/edit?botId=${botId}`);
  }, [navigate, botId]);

  const handleBackToBots = useCallback(() => {
    navigate('/bots');
  }, [navigate]);

  return (
    <div className="w-60 border-r bg-background flex flex-col h-screen">
      {/* Header */}
      <div className="h-14 flex items-center px-2 border-b shrink-0">
        <Button
          variant="ghost"
          size="sm"
          onClick={handleBackToBots}
          className="gap-1.5 text-muted-foreground hover:text-foreground px-2"
        >
          <ArrowLeft className="h-4 w-4" />
          <span className="text-sm">การเชื่อมต่อ</span>
        </Button>
      </div>

      {/* New Flow Button */}
      <div className="p-3 shrink-0">
        <Button
          variant="outline"
          className="w-full"
          onClick={handleCreateNew}
        >
          <Plus className="h-4 w-4 mr-2" />
          สร้าง Flow ใหม่
        </Button>
      </div>

      {/* Flow List */}
      <div className="flex-1 overflow-y-auto px-2">
        {isLoading ? (
          <div className="flex justify-center py-4">
            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
          </div>
        ) : sortedFlows.length === 0 ? (
          <p className="text-sm text-muted-foreground text-center py-4">
            ยังไม่มี Flow
          </p>
        ) : (
          <div className="space-y-0.5">
            {sortedFlows.map((flow) => (
              <FlowItem
                key={flow.id}
                flow={flow}
                isSelected={selectedFlowId === flow.id}
                onClick={() => handleFlowClick(flow.id)}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  );
});
