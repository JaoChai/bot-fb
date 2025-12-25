import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router';
import { toast } from 'sonner';

// UI Components
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';

// Hooks
import { useBotSettingsOperations, type UpdateSettingsPayload } from '@/hooks/useBotSettings';
import { useBots } from '@/hooks/useKnowledgeBase';
import type { BotSettings } from '@/types/api';

// Icons
import { ArrowLeft, Save, RotateCcw, Loader2 } from 'lucide-react';

const LANGUAGES = [
  { value: 'th', label: 'ไทย (Thai)' },
  { value: 'en', label: 'English' },
  { value: 'zh', label: '中文 (Chinese)' },
  { value: 'ja', label: '日本語 (Japanese)' },
  { value: 'ko', label: '한국어 (Korean)' },
] as const;

const RESPONSE_STYLES = [
  { value: 'professional', label: 'Professional', description: 'Formal and business-like' },
  { value: 'casual', label: 'Casual', description: 'Relaxed and conversational' },
  { value: 'friendly', label: 'Friendly', description: 'Warm and approachable' },
  { value: 'formal', label: 'Formal', description: 'Very formal and respectful' },
] as const;

const DAYS_OF_WEEK = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;
const DAY_LABELS: Record<string, string> = {
  mon: 'Monday',
  tue: 'Tuesday',
  wed: 'Wednesday',
  thu: 'Thursday',
  fri: 'Friday',
  sat: 'Saturday',
  sun: 'Sunday',
};

type FormState = Partial<BotSettings>;

export function BotSettingsPage() {
  const { botId } = useParams();
  const navigate = useNavigate();
  const numericBotId = botId ? parseInt(botId, 10) : null;

  const { data: botsResponse, isLoading: isLoadingBots } = useBots();
  const { settings, isLoading, isUpdating, updateSettings, error } = useBotSettingsOperations(numericBotId);

  const [formState, setFormState] = useState<FormState>({});
  const [hasChanges, setHasChanges] = useState(false);

  // Get the current bot
  const currentBot = botsResponse?.data?.find(b => b.id === numericBotId);

  // Initialize form state when settings load
  useEffect(() => {
    if (settings) {
      setFormState(settings);
      setHasChanges(false);
    }
  }, [settings]);

  // Update a single field
  const updateField = <K extends keyof FormState>(field: K, value: FormState[K]) => {
    setFormState(prev => ({ ...prev, [field]: value }));
    setHasChanges(true);
  };

  // Update response hours for a specific day
  const updateResponseHours = (day: string, field: 'start' | 'end', value: string) => {
    setFormState(prev => ({
      ...prev,
      response_hours: {
        ...(prev.response_hours || {}),
        [day]: {
          ...(prev.response_hours?.[day] || { start: '09:00', end: '18:00' }),
          [field]: value,
        },
      },
    }));
    setHasChanges(true);
  };

  // Save settings
  const handleSave = async () => {
    if (!updateSettings) return;

    try {
      const payload: UpdateSettingsPayload = { ...formState };
      // Remove readonly fields
      delete (payload as { id?: number }).id;
      delete (payload as { bot_id?: number }).bot_id;
      delete (payload as { created_at?: string }).created_at;
      delete (payload as { updated_at?: string }).updated_at;

      await updateSettings(payload);
      toast.success('Settings saved successfully');
      setHasChanges(false);
    } catch {
      toast.error('Failed to save settings');
    }
  };

  // Reset to saved values
  const handleReset = () => {
    if (settings) {
      setFormState(settings);
      setHasChanges(false);
    }
  };

  if (!numericBotId || isNaN(numericBotId)) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-muted-foreground">Invalid bot ID</p>
      </div>
    );
  }

  if (isLoading || isLoadingBots) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-destructive">Error loading settings: {error.message}</p>
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
            <h1 className="text-2xl font-bold tracking-tight">Bot Settings</h1>
            <p className="text-muted-foreground">
              Configure {currentBot?.name || 'bot'} behavior and limits
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={handleReset} disabled={!hasChanges || isUpdating}>
            <RotateCcw className="h-4 w-4 mr-2" />
            Reset
          </Button>
          <Button onClick={handleSave} disabled={!hasChanges || isUpdating}>
            {isUpdating ? (
              <Loader2 className="h-4 w-4 mr-2 animate-spin" />
            ) : (
              <Save className="h-4 w-4 mr-2" />
            )}
            Save Changes
          </Button>
        </div>
      </div>

      {/* Settings Tabs */}
      <Tabs defaultValue="general" className="space-y-4">
        <TabsList className="grid w-full grid-cols-5">
          <TabsTrigger value="general">General</TabsTrigger>
          <TabsTrigger value="limits">Limits</TabsTrigger>
          <TabsTrigger value="responses">Responses</TabsTrigger>
          <TabsTrigger value="hours">Business Hours</TabsTrigger>
          <TabsTrigger value="moderation">Moderation</TabsTrigger>
        </TabsList>

        {/* General Settings */}
        <TabsContent value="general">
          <Card>
            <CardHeader>
              <CardTitle>General Settings</CardTitle>
              <CardDescription>
                Configure language, response style, and basic preferences
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* Language */}
              <div className="space-y-2">
                <Label htmlFor="language">Language</Label>
                <Select
                  value={formState.language || 'th'}
                  onValueChange={(value) => updateField('language', value as BotSettings['language'])}
                >
                  <SelectTrigger id="language" className="w-[240px]">
                    <SelectValue placeholder="Select language" />
                  </SelectTrigger>
                  <SelectContent>
                    {LANGUAGES.map(lang => (
                      <SelectItem key={lang.value} value={lang.value}>
                        {lang.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-sm text-muted-foreground">
                  Primary language for bot responses
                </p>
              </div>

              <Separator />

              {/* Response Style */}
              <div className="space-y-3">
                <Label>Response Style</Label>
                <div className="grid grid-cols-2 gap-4">
                  {RESPONSE_STYLES.map(style => (
                    <div
                      key={style.value}
                      className={`p-4 border rounded-lg cursor-pointer transition-colors ${
                        formState.response_style === style.value
                          ? 'border-primary bg-primary/5'
                          : 'hover:border-muted-foreground/50'
                      }`}
                      onClick={() => updateField('response_style', style.value as BotSettings['response_style'])}
                    >
                      <p className="font-medium">{style.label}</p>
                      <p className="text-sm text-muted-foreground">{style.description}</p>
                    </div>
                  ))}
                </div>
              </div>

              <Separator />

              {/* Analytics & Storage */}
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label htmlFor="analytics">Enable Analytics</Label>
                    <p className="text-sm text-muted-foreground">
                      Track conversation metrics and user engagement
                    </p>
                  </div>
                  <Switch
                    id="analytics"
                    checked={formState.analytics_enabled ?? true}
                    onCheckedChange={(checked) => updateField('analytics_enabled', checked)}
                  />
                </div>

                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label htmlFor="save-conversations">Save Conversations</Label>
                    <p className="text-sm text-muted-foreground">
                      Store conversation history for review
                    </p>
                  </div>
                  <Switch
                    id="save-conversations"
                    checked={formState.save_conversations ?? true}
                    onCheckedChange={(checked) => updateField('save_conversations', checked)}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="auto-archive">Auto-archive after (days)</Label>
                  <Input
                    id="auto-archive"
                    type="number"
                    min={1}
                    max={365}
                    placeholder="Leave empty to disable"
                    className="w-[180px]"
                    value={formState.auto_archive_days ?? ''}
                    onChange={(e) => {
                      const val = e.target.value ? parseInt(e.target.value, 10) : null;
                      updateField('auto_archive_days', val);
                    }}
                  />
                  <p className="text-sm text-muted-foreground">
                    Automatically archive conversations older than this
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Limits Settings */}
        <TabsContent value="limits">
          <Card>
            <CardHeader>
              <CardTitle>Usage Limits</CardTitle>
              <CardDescription>
                Set message limits and rate limiting to prevent abuse
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="grid gap-6 md:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="daily-limit">Daily Message Limit</Label>
                  <Input
                    id="daily-limit"
                    type="number"
                    min={0}
                    max={100000}
                    value={formState.daily_message_limit ?? 1000}
                    onChange={(e) => updateField('daily_message_limit', parseInt(e.target.value, 10))}
                  />
                  <p className="text-sm text-muted-foreground">
                    Maximum messages per day (0 = unlimited)
                  </p>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="per-user-limit">Per-User Limit</Label>
                  <Input
                    id="per-user-limit"
                    type="number"
                    min={0}
                    max={10000}
                    value={formState.per_user_limit ?? 100}
                    onChange={(e) => updateField('per_user_limit', parseInt(e.target.value, 10))}
                  />
                  <p className="text-sm text-muted-foreground">
                    Messages per user per day (0 = unlimited)
                  </p>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="rate-limit">Rate Limit (per minute)</Label>
                  <Input
                    id="rate-limit"
                    type="number"
                    min={1}
                    max={1000}
                    value={formState.rate_limit_per_minute ?? 20}
                    onChange={(e) => updateField('rate_limit_per_minute', parseInt(e.target.value, 10))}
                  />
                  <p className="text-sm text-muted-foreground">
                    Max requests per minute per user
                  </p>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="max-tokens">Max Tokens per Response</Label>
                  <Input
                    id="max-tokens"
                    type="number"
                    min={100}
                    max={32000}
                    value={formState.max_tokens_per_response ?? 2000}
                    onChange={(e) => updateField('max_tokens_per_response', parseInt(e.target.value, 10))}
                  />
                  <p className="text-sm text-muted-foreground">
                    Maximum tokens in AI response
                  </p>
                </div>
              </div>

              <Separator />

              {/* HITL Settings */}
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label htmlFor="hitl">Human-in-the-Loop (HITL)</Label>
                    <p className="text-sm text-muted-foreground">
                      Transfer to human agent when triggered
                    </p>
                  </div>
                  <Switch
                    id="hitl"
                    checked={formState.hitl_enabled ?? false}
                    onCheckedChange={(checked) => updateField('hitl_enabled', checked)}
                  />
                </div>

                {formState.hitl_enabled && (
                  <div className="space-y-2 pl-4 border-l-2">
                    <Label htmlFor="hitl-triggers">HITL Trigger Keywords</Label>
                    <Textarea
                      id="hitl-triggers"
                      placeholder="help, support, agent, human (one per line)"
                      value={formState.hitl_triggers?.join('\n') || ''}
                      onChange={(e) => {
                        const triggers = e.target.value.split('\n').filter(t => t.trim());
                        updateField('hitl_triggers', triggers.length > 0 ? triggers : null);
                      }}
                      rows={4}
                    />
                    <p className="text-sm text-muted-foreground">
                      Keywords that trigger human takeover (one per line)
                    </p>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Auto-Responses */}
        <TabsContent value="responses">
          <Card>
            <CardHeader>
              <CardTitle>Auto-Responses</CardTitle>
              <CardDescription>
                Configure automated messages and typing behavior
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="space-y-2">
                <Label htmlFor="welcome">Welcome Message</Label>
                <Textarea
                  id="welcome"
                  placeholder="Hello! How can I help you today?"
                  value={formState.welcome_message || ''}
                  onChange={(e) => updateField('welcome_message', e.target.value || null)}
                  rows={3}
                />
                <p className="text-sm text-muted-foreground">
                  Sent when a user starts a new conversation
                </p>
              </div>

              <Separator />

              <div className="space-y-2">
                <Label htmlFor="fallback">Fallback Message</Label>
                <Textarea
                  id="fallback"
                  placeholder="I'm sorry, I couldn't understand your request. Please try again."
                  value={formState.fallback_message || ''}
                  onChange={(e) => updateField('fallback_message', e.target.value || null)}
                  rows={3}
                />
                <p className="text-sm text-muted-foreground">
                  Sent when bot cannot generate a response
                </p>
              </div>

              <Separator />

              {/* Typing Indicator */}
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div className="space-y-0.5">
                    <Label htmlFor="typing">Show Typing Indicator</Label>
                    <p className="text-sm text-muted-foreground">
                      Display "typing..." while generating response
                    </p>
                  </div>
                  <Switch
                    id="typing"
                    checked={formState.typing_indicator ?? true}
                    onCheckedChange={(checked) => updateField('typing_indicator', checked)}
                  />
                </div>

                {formState.typing_indicator && (
                  <div className="space-y-2 pl-4 border-l-2">
                    <Label htmlFor="typing-delay">Typing Delay (ms)</Label>
                    <Input
                      id="typing-delay"
                      type="number"
                      min={0}
                      max={5000}
                      className="w-[180px]"
                      value={formState.typing_delay_ms ?? 1000}
                      onChange={(e) => updateField('typing_delay_ms', parseInt(e.target.value, 10))}
                    />
                    <p className="text-sm text-muted-foreground">
                      Minimum delay before showing response (0-5000ms)
                    </p>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Business Hours */}
        <TabsContent value="hours">
          <Card>
            <CardHeader>
              <CardTitle>Business Hours</CardTitle>
              <CardDescription>
                Set when the bot is available to respond
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="hours-enabled">Enable Business Hours</Label>
                  <p className="text-sm text-muted-foreground">
                    Bot will only respond during specified hours
                  </p>
                </div>
                <Switch
                  id="hours-enabled"
                  checked={formState.response_hours_enabled ?? false}
                  onCheckedChange={(checked) => updateField('response_hours_enabled', checked)}
                />
              </div>

              {formState.response_hours_enabled && (
                <>
                  <Separator />

                  {/* Schedule Grid */}
                  <div className="space-y-4">
                    {DAYS_OF_WEEK.map(day => (
                      <div key={day} className="flex items-center gap-4">
                        <span className="w-24 font-medium">{DAY_LABELS[day]}</span>
                        <div className="flex items-center gap-2">
                          <Input
                            type="time"
                            className="w-32"
                            value={formState.response_hours?.[day]?.start || '09:00'}
                            onChange={(e) => updateResponseHours(day, 'start', e.target.value)}
                          />
                          <span className="text-muted-foreground">to</span>
                          <Input
                            type="time"
                            className="w-32"
                            value={formState.response_hours?.[day]?.end || '18:00'}
                            onChange={(e) => updateResponseHours(day, 'end', e.target.value)}
                          />
                        </div>
                      </div>
                    ))}
                  </div>

                  <Separator />

                  {/* Offline Message */}
                  <div className="space-y-2">
                    <Label htmlFor="offline">Offline Message</Label>
                    <Textarea
                      id="offline"
                      placeholder="We're currently offline. Our business hours are Monday-Friday, 9AM-6PM."
                      value={formState.offline_message || ''}
                      onChange={(e) => updateField('offline_message', e.target.value || null)}
                      rows={3}
                    />
                    <p className="text-sm text-muted-foreground">
                      Sent when user messages outside business hours
                    </p>
                  </div>
                </>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Content Moderation */}
        <TabsContent value="moderation">
          <Card>
            <CardHeader>
              <CardTitle>Content Moderation</CardTitle>
              <CardDescription>
                Filter and block inappropriate content
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="filter">Enable Content Filter</Label>
                  <p className="text-sm text-muted-foreground">
                    Filter messages containing blocked keywords
                  </p>
                </div>
                <Switch
                  id="filter"
                  checked={formState.content_filter_enabled ?? true}
                  onCheckedChange={(checked) => updateField('content_filter_enabled', checked)}
                />
              </div>

              {formState.content_filter_enabled && (
                <div className="space-y-2 pl-4 border-l-2">
                  <Label htmlFor="blocked-keywords">Blocked Keywords</Label>
                  <Textarea
                    id="blocked-keywords"
                    placeholder="spam, inappropriate, banned (one per line)"
                    value={formState.blocked_keywords?.join('\n') || ''}
                    onChange={(e) => {
                      const keywords = e.target.value.split('\n').filter(k => k.trim());
                      updateField('blocked_keywords', keywords.length > 0 ? keywords : null);
                    }}
                    rows={6}
                  />
                  <p className="text-sm text-muted-foreground">
                    Messages containing these words will be filtered (one per line)
                  </p>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Floating Save Bar (when changes exist) */}
      {hasChanges && (
        <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-50">
          <div className="flex items-center gap-3 bg-background border shadow-lg rounded-lg px-6 py-3">
            <span className="text-sm text-muted-foreground">You have unsaved changes</span>
            <Button variant="outline" size="sm" onClick={handleReset} disabled={isUpdating}>
              Discard
            </Button>
            <Button size="sm" onClick={handleSave} disabled={isUpdating}>
              {isUpdating ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : null}
              Save
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
