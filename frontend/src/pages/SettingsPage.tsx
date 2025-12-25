import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useUserSettingsOperations } from '@/hooks';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useToast } from '@/hooks/use-toast';
import { CheckCircle2, XCircle, Loader2, Eye, EyeOff, Trash2, ExternalLink } from 'lucide-react';

export function SettingsPage() {
  const { user } = useAuthStore();
  const { toast } = useToast();
  const {
    settings,
    isLoading,
    isUpdatingOpenRouter,
    isUpdatingLine,
    isTestingOpenRouter,
    isTestingLine,
    updateOpenRouter,
    updateLine,
    testOpenRouter,
    testLine,
    clearOpenRouter,
    clearLine,
  } = useUserSettingsOperations();

  // Form states
  const [openRouterKey, setOpenRouterKey] = useState('');
  const [openRouterModel, setOpenRouterModel] = useState(settings?.openrouter_model || 'openai/gpt-4o-mini');
  const [showOpenRouterKey, setShowOpenRouterKey] = useState(false);

  const [lineChannelSecret, setLineChannelSecret] = useState('');
  const [lineAccessToken, setLineAccessToken] = useState('');
  const [showLineSecret, setShowLineSecret] = useState(false);
  const [showLineToken, setShowLineToken] = useState(false);

  // Update local state when settings load
  useState(() => {
    if (settings?.openrouter_model) {
      setOpenRouterModel(settings.openrouter_model);
    }
  });

  // OpenRouter handlers
  const handleSaveOpenRouter = async () => {
    try {
      await updateOpenRouter({
        api_key: openRouterKey || undefined,
        model: openRouterModel,
      });
      setOpenRouterKey(''); // Clear the input
      toast({
        title: 'Saved',
        description: 'OpenRouter settings updated successfully',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to save OpenRouter settings',
        variant: 'destructive',
      });
    }
  };

  const handleTestOpenRouter = async () => {
    try {
      const result = await testOpenRouter();
      toast({
        title: result.success ? 'Success' : 'Failed',
        description: result.message,
        variant: result.success ? 'default' : 'destructive',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to test connection',
        variant: 'destructive',
      });
    }
  };

  const handleClearOpenRouter = async () => {
    try {
      await clearOpenRouter();
      toast({
        title: 'Cleared',
        description: 'OpenRouter API key has been removed',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to clear API key',
        variant: 'destructive',
      });
    }
  };

  // LINE handlers
  const handleSaveLine = async () => {
    try {
      await updateLine({
        channel_secret: lineChannelSecret || undefined,
        channel_access_token: lineAccessToken || undefined,
      });
      setLineChannelSecret('');
      setLineAccessToken('');
      toast({
        title: 'Saved',
        description: 'LINE settings updated successfully',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to save LINE settings',
        variant: 'destructive',
      });
    }
  };

  const handleTestLine = async () => {
    try {
      const result = await testLine();
      toast({
        title: result.success ? 'Success' : 'Failed',
        description: result.message,
        variant: result.success ? 'default' : 'destructive',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to test LINE connection',
        variant: 'destructive',
      });
    }
  };

  const handleClearLine = async () => {
    try {
      await clearLine();
      toast({
        title: 'Cleared',
        description: 'LINE credentials have been removed',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to clear LINE credentials',
        variant: 'destructive',
      });
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Settings</h1>
        <p className="text-muted-foreground">
          Manage your account settings, API keys, and integrations
        </p>
      </div>

      {/* Profile Settings */}
      <Card>
        <CardHeader>
          <CardTitle>Profile</CardTitle>
          <CardDescription>
            Update your personal information
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="name">Name</Label>
            <Input id="name" defaultValue={user?.name || ''} />
          </div>
          <div className="space-y-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" type="email" defaultValue={user?.email || ''} disabled />
            <p className="text-xs text-muted-foreground">
              Contact support to change your email address
            </p>
          </div>
          <Button>Save changes</Button>
        </CardContent>
      </Card>

      <Separator />

      {/* OpenRouter API Settings */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="flex items-center gap-2">
                OpenRouter API
                {settings?.openrouter_configured ? (
                  <CheckCircle2 className="h-5 w-5 text-green-500" />
                ) : (
                  <XCircle className="h-5 w-5 text-muted-foreground" />
                )}
              </CardTitle>
              <CardDescription>
                Configure your OpenRouter API key for AI responses
              </CardDescription>
            </div>
            <a
              href="https://openrouter.ai/keys"
              target="_blank"
              rel="noopener noreferrer"
              className="text-sm text-muted-foreground hover:text-primary flex items-center gap-1"
            >
              Get API Key <ExternalLink className="h-3 w-3" />
            </a>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* API Key */}
          <div className="space-y-2">
            <Label htmlFor="openrouter-key">API Key</Label>
            <div className="flex gap-2">
              <div className="relative flex-1">
                <Input
                  id="openrouter-key"
                  type={showOpenRouterKey ? 'text' : 'password'}
                  placeholder={settings?.openrouter_api_key_masked || 'sk-or-v1-...'}
                  value={openRouterKey}
                  onChange={(e) => setOpenRouterKey(e.target.value)}
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="absolute right-0 top-0 h-full px-3 py-2 hover:bg-transparent"
                  onClick={() => setShowOpenRouterKey(!showOpenRouterKey)}
                >
                  {showOpenRouterKey ? (
                    <EyeOff className="h-4 w-4" />
                  ) : (
                    <Eye className="h-4 w-4" />
                  )}
                </Button>
              </div>
              {settings?.openrouter_configured && (
                <Button
                  variant="outline"
                  size="icon"
                  onClick={handleClearOpenRouter}
                  title="Clear API Key"
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              )}
            </div>
            {settings?.openrouter_api_key_masked && (
              <p className="text-xs text-muted-foreground">
                Current key: {settings.openrouter_api_key_masked}
              </p>
            )}
          </div>

          {/* Model Name */}
          <div className="space-y-2">
            <Label htmlFor="openrouter-model">Model</Label>
            <Input
              id="openrouter-model"
              placeholder="openai/gpt-4o-mini"
              value={openRouterModel}
              onChange={(e) => setOpenRouterModel(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">
              Enter model name directly, e.g., <code className="bg-muted px-1 rounded">openai/gpt-4o-mini</code>, <code className="bg-muted px-1 rounded">anthropic/claude-3.5-sonnet</code>
            </p>
          </div>

          {/* Actions */}
          <div className="flex gap-2">
            <Button onClick={handleSaveOpenRouter} disabled={isUpdatingOpenRouter}>
              {isUpdatingOpenRouter && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Save
            </Button>
            <Button
              variant="outline"
              onClick={handleTestOpenRouter}
              disabled={isTestingOpenRouter || !settings?.openrouter_configured}
            >
              {isTestingOpenRouter && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Test Connection
            </Button>
          </div>
        </CardContent>
      </Card>

      <Separator />

      {/* LINE Channel Settings */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="flex items-center gap-2">
                LINE Channel
                {settings?.line_configured ? (
                  <CheckCircle2 className="h-5 w-5 text-green-500" />
                ) : (
                  <XCircle className="h-5 w-5 text-muted-foreground" />
                )}
              </CardTitle>
              <CardDescription>
                Configure your LINE Messaging API credentials
              </CardDescription>
            </div>
            <a
              href="https://developers.line.biz/console/"
              target="_blank"
              rel="noopener noreferrer"
              className="text-sm text-muted-foreground hover:text-primary flex items-center gap-1"
            >
              LINE Developers <ExternalLink className="h-3 w-3" />
            </a>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Channel Secret */}
          <div className="space-y-2">
            <Label htmlFor="line-secret">Channel Secret</Label>
            <div className="relative">
              <Input
                id="line-secret"
                type={showLineSecret ? 'text' : 'password'}
                placeholder={settings?.line_channel_secret_masked || 'Enter Channel Secret'}
                value={lineChannelSecret}
                onChange={(e) => setLineChannelSecret(e.target.value)}
              />
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="absolute right-0 top-0 h-full px-3 py-2 hover:bg-transparent"
                onClick={() => setShowLineSecret(!showLineSecret)}
              >
                {showLineSecret ? (
                  <EyeOff className="h-4 w-4" />
                ) : (
                  <Eye className="h-4 w-4" />
                )}
              </Button>
            </div>
            {settings?.line_channel_secret_masked && (
              <p className="text-xs text-muted-foreground">
                Current: {settings.line_channel_secret_masked}
              </p>
            )}
          </div>

          {/* Channel Access Token */}
          <div className="space-y-2">
            <Label htmlFor="line-token">Channel Access Token</Label>
            <div className="relative">
              <Input
                id="line-token"
                type={showLineToken ? 'text' : 'password'}
                placeholder={settings?.line_channel_access_token_masked || 'Enter Channel Access Token'}
                value={lineAccessToken}
                onChange={(e) => setLineAccessToken(e.target.value)}
              />
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="absolute right-0 top-0 h-full px-3 py-2 hover:bg-transparent"
                onClick={() => setShowLineToken(!showLineToken)}
              >
                {showLineToken ? (
                  <EyeOff className="h-4 w-4" />
                ) : (
                  <Eye className="h-4 w-4" />
                )}
              </Button>
            </div>
            {settings?.line_channel_access_token_masked && (
              <p className="text-xs text-muted-foreground">
                Current: {settings.line_channel_access_token_masked}
              </p>
            )}
          </div>

          {/* Actions */}
          <div className="flex gap-2">
            <Button onClick={handleSaveLine} disabled={isUpdatingLine}>
              {isUpdatingLine && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Save
            </Button>
            <Button
              variant="outline"
              onClick={handleTestLine}
              disabled={isTestingLine || !settings?.line_configured}
            >
              {isTestingLine && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Test Connection
            </Button>
            {settings?.line_configured && (
              <Button variant="outline" onClick={handleClearLine}>
                <Trash2 className="mr-2 h-4 w-4" />
                Clear
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      <Separator />

      {/* Danger Zone */}
      <Card className="border-destructive/50">
        <CardHeader>
          <CardTitle className="text-destructive">Danger Zone</CardTitle>
          <CardDescription>
            Irreversible and destructive actions
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button variant="destructive">Delete Account</Button>
        </CardContent>
      </Card>
    </div>
  );
}
