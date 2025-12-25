import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { useBots } from '@/hooks/useKnowledgeBase';
import { Loader2, Settings, MessageSquare, Bot as BotIcon, Plus } from 'lucide-react';

export function BotsPage() {
  const { data: botsResponse, isLoading, error } = useBots();
  const bots = botsResponse?.data || [];

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-destructive">Error loading bots: {error.message}</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Bots</h1>
          <p className="text-muted-foreground">
            Manage your chatbots and their configurations
          </p>
        </div>
        <Button>
          <Plus className="h-4 w-4 mr-2" />
          Create Bot
        </Button>
      </div>

      {bots.length === 0 ? (
        /* Empty state */
        <Card>
          <CardHeader className="text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
              <BotIcon className="h-6 w-6 text-muted-foreground" />
            </div>
            <CardTitle>No bots yet</CardTitle>
            <CardDescription>
              Create your first bot to get started with AI-powered conversations
            </CardDescription>
          </CardHeader>
          <CardContent className="text-center">
            <Button>
              <Plus className="h-4 w-4 mr-2" />
              Create your first bot
            </Button>
          </CardContent>
        </Card>
      ) : (
        /* Bot list */
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {bots.map(bot => (
            <Card key={bot.id} className="flex flex-col">
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                  <div className="space-y-1">
                    <CardTitle className="text-lg">{bot.name}</CardTitle>
                    <CardDescription className="line-clamp-2">
                      {bot.description || 'No description'}
                    </CardDescription>
                  </div>
                  <Badge
                    variant={bot.status === 'active' ? 'default' : 'secondary'}
                    className={bot.status === 'active' ? 'bg-green-500' : ''}
                  >
                    {bot.status}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent className="flex-1">
                <div className="flex items-center gap-4 text-sm text-muted-foreground mb-4">
                  <div className="flex items-center gap-1">
                    <MessageSquare className="h-4 w-4" />
                    <span>{bot.total_messages || 0} messages</span>
                  </div>
                  <div className="flex items-center gap-1">
                    <span>{bot.total_conversations || 0} conversations</span>
                  </div>
                </div>

                {/* Channel badge */}
                <Badge variant="outline" className="mb-4">
                  {bot.channel_type?.toUpperCase() || 'N/A'}
                </Badge>
              </CardContent>

              {/* Actions */}
              <div className="border-t p-4 mt-auto">
                <div className="flex flex-col gap-2">
                  <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" asChild className="flex-1">
                      <Link to={`/bots/${bot.id}/settings`}>
                        <Settings className="h-4 w-4 mr-2" />
                        Settings
                      </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild className="flex-1">
                      <Link to={`/flows?botId=${bot.id}`}>
                        Flows
                      </Link>
                    </Button>
                  </div>
                  <Button variant="outline" size="sm" asChild className="w-full">
                    <Link to={`/knowledge-base?botId=${bot.id}`}>
                      Knowledge Base
                    </Link>
                  </Button>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
