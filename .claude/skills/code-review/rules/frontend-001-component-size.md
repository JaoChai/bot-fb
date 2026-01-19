---
id: frontend-001-component-size
title: Component Size Limits
impact: HIGH
impactDescription: "Large components are hard to understand, test, and maintain"
category: frontend
tags: [react, component, architecture, refactoring]
relatedRules: [frontend-002-custom-hooks, frontend-003-prop-drilling]
---

## Why This Matters

Components over 150-200 lines typically do too much. They're harder to understand, test, and reuse. Breaking them down improves maintainability.

## Bad Example

```tsx
// 400+ line component
function BotDashboard() {
  // State management (50 lines)
  const [bots, setBots] = useState([]);
  const [selectedBot, setSelectedBot] = useState(null);
  const [conversations, setConversations] = useState([]);
  const [messages, setMessages] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  // ... 20 more state variables

  // Effects (100 lines)
  useEffect(() => {
    fetchBots();
  }, []);

  useEffect(() => {
    if (selectedBot) fetchConversations();
  }, [selectedBot]);

  // Handlers (100 lines)
  const handleBotSelect = () => { /* ... */ };
  const handleSendMessage = () => { /* ... */ };
  // ... 20 more handlers

  // Render (150 lines)
  return (
    <div>
      {/* All UI in one massive return */}
    </div>
  );
}
```

**Why it's wrong:**
- Too many responsibilities
- Hard to find specific logic
- Can't test parts independently
- Reusability impossible

## Good Example

```tsx
// Main component: orchestration only
function BotDashboard() {
  const { selectedBotId, selectBot } = useBotSelection();

  return (
    <DashboardLayout>
      <BotSidebar onSelect={selectBot} />
      <MainContent>
        {selectedBotId ? (
          <BotDetail botId={selectedBotId} />
        ) : (
          <EmptyState message="Select a bot" />
        )}
      </MainContent>
    </DashboardLayout>
  );
}

// Focused child components
function BotSidebar({ onSelect }: { onSelect: (id: number) => void }) {
  const { data: bots } = useBots();

  return (
    <aside>
      {bots?.map(bot => (
        <BotListItem key={bot.id} bot={bot} onSelect={onSelect} />
      ))}
    </aside>
  );
}

function BotDetail({ botId }: { botId: number }) {
  const { data: bot } = useBot(botId);

  return (
    <div>
      <BotHeader bot={bot} />
      <BotStats botId={botId} />
      <ConversationList botId={botId} />
    </div>
  );
}
```

**Why it's better:**
- Each component < 50 lines
- Single responsibility
- Testable independently
- Reusable

## Review Checklist

- [ ] Components under 150 lines
- [ ] Single responsibility per component
- [ ] Logic extracted to custom hooks
- [ ] Child components for complex renders
- [ ] File name matches main export

## Detection

```bash
# Large components
wc -l src/components/**/*.tsx | sort -n | tail -20

# Components with many useState
grep -c "useState" src/components/**/*.tsx | sort -t: -k2 -n | tail -10
```

## Project-Specific Notes

**BotFacebook Component Structure:**

```
src/pages/DashboardPage.tsx     # ~50 lines, orchestration
src/components/dashboard/
├── BotSidebar.tsx              # ~80 lines
├── BotDetail.tsx               # ~60 lines
├── ConversationPanel.tsx       # ~100 lines
├── MessageList.tsx             # ~70 lines
└── MessageInput.tsx            # ~50 lines

# Each focused on one thing
# State in hooks, not components
```
