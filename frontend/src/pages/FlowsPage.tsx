import { useState } from 'react';
import { Link, useSearchParams, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useFlowOperations } from '@/hooks/useFlows';
import { useBots } from '@/hooks/useKnowledgeBase';
import { useToast } from '@/hooks/use-toast';
import {
  Loader2,
  Plus,
  MoreVertical,
  Star,
  Copy,
  Trash2,
  Edit,
  Workflow,
  Thermometer,
  BookOpen,
  ArrowLeft,
} from 'lucide-react';
import type { Flow } from '@/types/api';

export function FlowsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const { toast } = useToast();

  // Get botId from URL params
  const botIdParam = searchParams.get('botId');
  const botId = botIdParam ? parseInt(botIdParam, 10) : null;

  // Bot selector for when no bot is selected
  const { data: botsResponse, isLoading: isBotsLoading } = useBots();
  const bots = botsResponse?.data || [];

  // Flow operations
  const {
    flows,
    isLoading,
    isDeleting,
    isDuplicating,
    isSettingDefault,
    error,
    deleteFlow,
    duplicateFlow,
    setDefaultFlow,
  } = useFlowOperations(botId);

  // Delete dialog state
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [flowToDelete, setFlowToDelete] = useState<Flow | null>(null);

  const selectedBot = bots.find((b) => b.id === botId);

  // Handle bot selection
  const handleBotSelect = (value: string) => {
    setSearchParams({ botId: value });
  };

  // Handle delete
  const handleDeleteClick = (flow: Flow) => {
    setFlowToDelete(flow);
    setDeleteDialogOpen(true);
  };

  const handleDeleteConfirm = async () => {
    if (!flowToDelete || !deleteFlow) return;
    try {
      await deleteFlow(flowToDelete.id);
      toast({
        title: 'Flow deleted',
        description: `"${flowToDelete.name}" has been deleted.`,
      });
    } catch (err) {
      toast({
        title: 'Error',
        description: err instanceof Error ? err.message : 'Failed to delete flow',
        variant: 'destructive',
      });
    } finally {
      setDeleteDialogOpen(false);
      setFlowToDelete(null);
    }
  };

  // Handle duplicate
  const handleDuplicate = async (flow: Flow) => {
    if (!duplicateFlow) return;
    try {
      await duplicateFlow(flow.id);
      toast({
        title: 'Flow duplicated',
        description: `A copy of "${flow.name}" has been created.`,
      });
    } catch (err) {
      toast({
        title: 'Error',
        description: err instanceof Error ? err.message : 'Failed to duplicate flow',
        variant: 'destructive',
      });
    }
  };

  // Handle set default
  const handleSetDefault = async (flow: Flow) => {
    if (!setDefaultFlow || flow.is_default) return;
    try {
      await setDefaultFlow(flow.id);
      toast({
        title: 'Default flow updated',
        description: `"${flow.name}" is now the default flow.`,
      });
    } catch (err) {
      toast({
        title: 'Error',
        description: err instanceof Error ? err.message : 'Failed to set default flow',
        variant: 'destructive',
      });
    }
  };

  // No bot selected - show bot selector
  if (!botId) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Flow Builder</h1>
          <p className="text-muted-foreground">Select a bot to manage its conversation flows</p>
        </div>

        {isBotsLoading ? (
          <div className="flex items-center justify-center h-64">
            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
          </div>
        ) : bots.length === 0 ? (
          <Card>
            <CardHeader className="text-center">
              <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                <Workflow className="h-6 w-6 text-muted-foreground" />
              </div>
              <CardTitle>No bots available</CardTitle>
              <CardDescription>Create a bot first to start building flows</CardDescription>
            </CardHeader>
            <CardContent className="text-center">
              <Button asChild>
                <Link to="/bots">
                  <Plus className="h-4 w-4 mr-2" />
                  Go to Bots
                </Link>
              </Button>
            </CardContent>
          </Card>
        ) : (
          <Card className="max-w-md">
            <CardHeader>
              <CardTitle>Select a Bot</CardTitle>
              <CardDescription>Choose which bot's flows you want to manage</CardDescription>
            </CardHeader>
            <CardContent>
              <Select onValueChange={handleBotSelect}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a bot..." />
                </SelectTrigger>
                <SelectContent>
                  {bots.map((bot) => (
                    <SelectItem key={bot.id} value={bot.id.toString()}>
                      {bot.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </CardContent>
          </Card>
        )}
      </div>
    );
  }

  // Loading state
  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  // Error state
  if (error) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-destructive">Error loading flows: {error.message}</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => navigate('/bots')}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-bold tracking-tight">Flows</h1>
              {selectedBot && (
                <Badge variant="outline" className="font-normal">
                  {selectedBot.name}
                </Badge>
              )}
            </div>
            <p className="text-muted-foreground">
              Manage conversation flows and prompts for your bot
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Select value={botId.toString()} onValueChange={handleBotSelect}>
            <SelectTrigger className="w-[180px]">
              <SelectValue placeholder="Select bot" />
            </SelectTrigger>
            <SelectContent>
              {bots.map((bot) => (
                <SelectItem key={bot.id} value={bot.id.toString()}>
                  {bot.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Button asChild>
            <Link to={`/flows/new?botId=${botId}`}>
              <Plus className="h-4 w-4 mr-2" />
              New Flow
            </Link>
          </Button>
        </div>
      </div>

      {/* Flow list */}
      {flows.length === 0 ? (
        <Card>
          <CardHeader className="text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
              <Workflow className="h-6 w-6 text-muted-foreground" />
            </div>
            <CardTitle>No flows yet</CardTitle>
            <CardDescription>
              Create your first flow to define how your bot responds to conversations
            </CardDescription>
          </CardHeader>
          <CardContent className="text-center">
            <Button asChild>
              <Link to={`/flows/new?botId=${botId}`}>
                <Plus className="h-4 w-4 mr-2" />
                Create your first flow
              </Link>
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {[...flows]
            .sort((a, b) => {
              // Base flow (is_default) always first
              if (a.is_default && !b.is_default) return -1;
              if (!a.is_default && b.is_default) return 1;
              return 0;
            })
            .map((flow) => (
            <Card
              key={flow.id}
              className={`flex flex-col transition-all duration-200 hover:shadow-md cursor-pointer ${
                flow.is_default
                  ? 'border-l-4 border-l-orange-500 ring-1 ring-orange-500/20 bg-orange-50/30 dark:bg-orange-950/10'
                  : ''
              }`}
            >
              {/* Orange gradient header for Base Flow */}
              {flow.is_default && (
                <div className="h-1 bg-gradient-to-r from-orange-500 to-orange-400" />
              )}
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                  <div className="space-y-1 flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      {flow.is_default && (
                        <svg className="h-4 w-4 text-orange-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                          <path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v14l-5-2.5L5 18V4z" />
                        </svg>
                      )}
                      <CardTitle className="text-lg truncate">{flow.name}</CardTitle>
                      {flow.is_default && (
                        <Badge className="shrink-0 bg-orange-500 hover:bg-orange-600 text-white">
                          Base Flow
                        </Badge>
                      )}
                    </div>
                    <CardDescription className="line-clamp-2">
                      {flow.description || 'No description'}
                    </CardDescription>
                  </div>
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="icon" className="shrink-0">
                        <MoreVertical className="h-4 w-4" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem asChild>
                        <Link to={`/flows/${flow.id}/edit?botId=${botId}`}>
                          <Edit className="h-4 w-4 mr-2" />
                          Edit
                        </Link>
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        onClick={() => handleDuplicate(flow)}
                        disabled={isDuplicating}
                      >
                        <Copy className="h-4 w-4 mr-2" />
                        Duplicate
                      </DropdownMenuItem>
                      {!flow.is_default && (
                        <>
                          <DropdownMenuItem
                            onClick={() => handleSetDefault(flow)}
                            disabled={isSettingDefault}
                          >
                            <Star className="h-4 w-4 mr-2" />
                            Set as Default
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => handleDeleteClick(flow)}
                            className="text-destructive focus:text-destructive"
                            disabled={isDeleting}
                          >
                            <Trash2 className="h-4 w-4 mr-2" />
                            Delete
                          </DropdownMenuItem>
                        </>
                      )}
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </CardHeader>
              <CardContent className="flex-1">
                {/* Flow metadata */}
                <div className="flex flex-wrap gap-2 text-sm text-muted-foreground mb-4">
                  <div className="flex items-center gap-1">
                    <Thermometer className="h-4 w-4" />
                    <span>Temp: {flow.temperature}</span>
                  </div>
                  {flow.knowledge_bases && flow.knowledge_bases.length > 0 && (
                    <div className="flex items-center gap-1">
                      <BookOpen className="h-4 w-4" />
                      <span className="truncate max-w-[120px]">
                        {flow.knowledge_bases.length === 1
                          ? flow.knowledge_bases[0].name
                          : `${flow.knowledge_bases.length} KBs`}
                      </span>
                    </div>
                  )}
                </div>

                {/* Model badge */}
                <Badge variant="outline" className="mb-2">
                  {flow.model || 'Default Model'}
                </Badge>

                {/* Language badge */}
                <Badge variant="outline" className="ml-2">
                  {flow.language?.toUpperCase() || 'TH'}
                </Badge>
              </CardContent>

              {/* Actions */}
              <div className="border-t p-4 mt-auto">
                <Button variant="outline" size="sm" asChild className="w-full">
                  <Link to={`/flows/${flow.id}/edit?botId=${botId}`}>
                    <Edit className="h-4 w-4 mr-2" />
                    Edit Flow
                  </Link>
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Delete confirmation dialog */}
      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Flow</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{flowToDelete?.name}"? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteConfirm}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {isDeleting ? (
                <>
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  Deleting...
                </>
              ) : (
                'Delete'
              )}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
