---
id: react-001-extract-component
title: Extract Component Refactoring
impact: HIGH
impactDescription: "Break down large components into smaller, reusable pieces"
category: react
tags: [extract, component, react, composition]
relatedRules: [react-002-extract-hook, smell-001-long-method]
---

## Code Smell

- Component > 200 lines
- Multiple responsibilities in one component
- JSX deeply nested
- Hard to understand at a glance
- Same UI patterns repeated

## Root Cause

1. Feature creep over time
2. "Just add it here" mindset
3. No component planning
4. Copy-paste development
5. Lack of design system

## When to Apply

**Apply when:**
- Component > 150 lines
- Clear UI sections visible
- Part is reusable
- Testing is difficult

**Don't apply when:**
- Component is readable
- Extraction adds prop drilling
- One-time use case

## Solution

### Before

```tsx
function BotSettingsPage() {
  const { botId } = useParams();
  const { data: bot, isLoading } = useQuery({
    queryKey: ['bot', botId],
    queryFn: () => api.bots.get(botId),
  });

  const updateMutation = useMutation({
    mutationFn: api.bots.update,
    onSuccess: () => queryClient.invalidateQueries(['bot', botId]),
  });

  if (isLoading) return <Spinner />;

  return (
    <div className="space-y-6">
      {/* Header section - 30 lines */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">{bot.name}</h1>
          <p className="text-muted-foreground">Configure your bot settings</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handleDuplicate}>
            <Copy className="h-4 w-4 mr-2" />
            Duplicate
          </Button>
          <Button variant="destructive" onClick={handleDelete}>
            <Trash className="h-4 w-4 mr-2" />
            Delete
          </Button>
        </div>
      </div>

      {/* General settings - 50 lines */}
      <Card>
        <CardHeader>
          <CardTitle>General Settings</CardTitle>
        </CardHeader>
        <CardContent>
          <Form onSubmit={handleGeneralSubmit}>
            <FormField name="name" label="Bot Name">
              <Input defaultValue={bot.name} />
            </FormField>
            <FormField name="description" label="Description">
              <Textarea defaultValue={bot.description} />
            </FormField>
            {/* ... more fields */}
          </Form>
        </CardContent>
      </Card>

      {/* AI settings - 60 lines */}
      <Card>
        <CardHeader>
          <CardTitle>AI Settings</CardTitle>
        </CardHeader>
        <CardContent>
          {/* ... AI configuration UI */}
        </CardContent>
      </Card>

      {/* Platform settings - 40 lines */}
      {/* ... */}
    </div>
  );
}
```

### After

```tsx
// BotSettingsPage.tsx - Main container (slim)
function BotSettingsPage() {
  const { botId } = useParams<{ botId: string }>();
  const { data: bot, isLoading } = useBotQuery(botId);

  if (isLoading) return <PageSkeleton />;
  if (!bot) return <NotFound message="Bot not found" />;

  return (
    <div className="space-y-6">
      <BotSettingsHeader bot={bot} />
      <GeneralSettingsCard bot={bot} />
      <AISettingsCard bot={bot} />
      <PlatformSettingsCard bot={bot} />
    </div>
  );
}

// components/bot/BotSettingsHeader.tsx
interface BotSettingsHeaderProps {
  bot: Bot;
}

function BotSettingsHeader({ bot }: BotSettingsHeaderProps) {
  const { duplicate, delete: deleteBot } = useBotActions(bot.id);

  return (
    <div className="flex items-center justify-between">
      <div>
        <h1 className="text-2xl font-bold">{bot.name}</h1>
        <p className="text-muted-foreground">Configure your bot settings</p>
      </div>
      <div className="flex gap-2">
        <Button variant="outline" onClick={duplicate}>
          <Copy className="h-4 w-4 mr-2" />
          Duplicate
        </Button>
        <AlertDialog>
          <AlertDialogTrigger asChild>
            <Button variant="destructive">
              <Trash className="h-4 w-4 mr-2" />
              Delete
            </Button>
          </AlertDialogTrigger>
          <AlertDialogContent>
            {/* Confirmation dialog */}
          </AlertDialogContent>
        </AlertDialog>
      </div>
    </div>
  );
}

// components/bot/GeneralSettingsCard.tsx
interface GeneralSettingsCardProps {
  bot: Bot;
}

function GeneralSettingsCard({ bot }: GeneralSettingsCardProps) {
  const { updateBot, isPending } = useUpdateBot(bot.id);
  const form = useForm<GeneralSettings>({
    defaultValues: {
      name: bot.name,
      description: bot.description,
    },
  });

  return (
    <Card>
      <CardHeader>
        <CardTitle>General Settings</CardTitle>
      </CardHeader>
      <CardContent>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(updateBot)} className="space-y-4">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Bot Name</FormLabel>
                  <FormControl>
                    <Input {...field} />
                  </FormControl>
                </FormItem>
              )}
            />
            {/* More fields */}
            <Button type="submit" disabled={isPending}>
              {isPending ? 'Saving...' : 'Save Changes'}
            </Button>
          </form>
        </Form>
      </CardContent>
    </Card>
  );
}
```

### Step-by-Step

1. **Identify extraction candidates**
   - Look for clear sections (comments, visual blocks)
   - Find repeated patterns
   - Spot testable units

2. **Create component file**
   ```bash
   touch src/components/bot/GeneralSettingsCard.tsx
   ```

3. **Define props interface**
   ```tsx
   interface Props {
     bot: Bot;
     onUpdate?: (bot: Bot) => void;
   }
   ```

4. **Move JSX to new component**
   - Cut relevant JSX
   - Paste into new component
   - Add necessary imports

5. **Replace with component usage**
   - Import new component
   - Pass required props
   - Run tests

## Verification

```bash
# Type check
npm run type-check

# Test
npm run test

# Visual check
# Compare UI before/after
```

## Anti-Patterns

- **Premature extraction**: < 50 lines is usually fine
- **Prop drilling**: If >3 levels, use context
- **Breaking cohesion**: Keep related logic together
- **Over-componentization**: Not everything needs its own file

## Project-Specific Notes

**BotFacebook Context:**
- Components location: `src/components/`
- Feature components: `src/components/{feature}/`
- UI primitives: `src/components/ui/`
- Pattern: Props interface above component
