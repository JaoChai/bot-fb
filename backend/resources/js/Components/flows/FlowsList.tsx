/**
 * FlowsList - List of flows with navigation
 *
 * Copied from frontend and adapted for Inertia context
 * TODO: Replace useNavigate with Inertia router.visit or Link component
 */

import { memo, useMemo, useCallback } from 'react';
// TODO: Replace with Inertia router
// import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import {
  Plus,
  Link2,
  Settings,
  ArrowLeft,
  Bot,
  Loader2,
  Sparkles,
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
const FlowListItemComponent = memo(function FlowListItemComponent({
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
        ${flow.is_default ? 'border-l-4 border-l-foreground' : 'border-l-4 border-l-transparent'}
        ${isSelected
          ? 'bg-foreground text-background font-medium'
          : 'hover:bg-muted'
        }`}
    >
      <div className="flex items-center gap-2">
        {flow.is_default && (
          <svg className={`h-4 w-4 shrink-0 ${isSelected ? 'text-background' : 'text-foreground'}`} fill="currentColor" viewBox="0 0 20 20">
            <path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v14l-5-2.5L5 18V4z" />
          </svg>
        )}
        <span className="truncate font-medium">{flow.name}</span>
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
  // TODO: Replace with Inertia router
  // Placeholder navigation functions
  const navigate = (path: string) => {
    // TODO: Use router.visit(path) from @inertiajs/react
    console.log('Navigate to:', path);
    window.location.href = path;
  };

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
  }, [botId]);

  const handleFlowClick = useCallback((flowId: number) => {
    navigate(`/flows/${flowId}/edit?botId=${botId}`);
  }, [botId]);

  const handleEditConnection = useCallback(() => {
    navigate(`/bots/${botId}/edit`);
  }, [botId]);

  const handleBotSettings = useCallback(() => {
    navigate(`/bots/${botId}/settings`);
  }, [botId]);

  const handleBackToBots = useCallback(() => {
    navigate('/bots');
  }, []);

  return (
    <div className="w-52 border-r bg-card flex flex-col">
      {/* Logo */}
      <div className="h-14 flex items-center px-4 border-b gap-2">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-foreground text-background">
          <Sparkles className="h-4 w-4" />
        </div>
        <span className="text-sm font-semibold">BotJao</span>
      </div>

      {/* Create New Flow Button */}
      <div className="p-3">
        <Button
          className="w-full"
          variant="default"
          onClick={handleCreateNew}
        >
          <Plus className="h-4 w-4 mr-2" />
          Create New Flow
        </Button>
      </div>

      {/* Flow List */}
      <div className="flex-1 overflow-y-auto">
        {isLoading ? (
          <div className="flex justify-center py-4">
            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
          </div>
        ) : flows.length === 0 ? (
          <p className="text-sm text-muted-foreground text-center py-4">No flows yet</p>
        ) : (
          <div className="space-y-1 px-2">
            {sortedFlows.map((flow) => (
              <FlowListItemComponent
                key={flow.id}
                flow={flow}
                isSelected={selectedFlowId === flow.id}
                onClick={() => handleFlowClick(flow.id)}
              />
            ))}
          </div>
        )}
      </div>

      {/* Settings Buttons */}
      <div className="p-3 border-t space-y-1">
        <Button
          variant="ghost"
          size="sm"
          className="w-full justify-start text-muted-foreground hover:text-foreground"
        >
          <Link2 className="h-4 w-4 mr-2" />
          Internal Links
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="w-full justify-start text-muted-foreground hover:text-foreground"
          onClick={handleEditConnection}
        >
          <Settings className="h-4 w-4 mr-2" />
          Edit Connection
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="w-full justify-start text-muted-foreground hover:text-foreground"
          onClick={handleBotSettings}
        >
          <Bot className="h-4 w-4 mr-2" />
          Bot Settings
        </Button>
      </div>

      {/* Back Navigation - Separated for clarity */}
      <div className="p-3 pt-0">
        <Button
          variant="ghost"
          size="sm"
          className="w-full justify-start text-muted-foreground hover:text-foreground"
          onClick={handleBackToBots}
        >
          <ArrowLeft className="h-4 w-4 mr-2" />
          Back to Connections
        </Button>
      </div>
    </div>
  );
});
