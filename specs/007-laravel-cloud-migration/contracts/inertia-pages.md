# Inertia Page Contracts

**Feature Branch**: `007-laravel-cloud-migration`
**Date**: 2026-01-09

## Overview

This document defines the contract between Laravel controllers and React Inertia pages. Each page receives typed props from its corresponding controller.

---

## Shared Props (All Pages)

```typescript
// resources/js/types/index.d.ts
interface SharedProps {
    auth: {
        user: {
            id: number
            name: string
            email: string
        } | null
    }
    flash: {
        success: string | null
        error: string | null
    }
    bots: Array<{
        id: number
        name: string
    }>
}
```

---

## Auth Pages

### Login Page

**Route**: `GET /login`
**Controller**: `Auth\LoginController@create`

```typescript
interface LoginPageProps extends SharedProps {
    canResetPassword: boolean
    status?: string
}
```

### Register Page

**Route**: `GET /register`
**Controller**: `Auth\RegisterController@create`

```typescript
interface RegisterPageProps extends SharedProps {
    // No additional props
}
```

---

## Dashboard Page

**Route**: `GET /dashboard`
**Controller**: `DashboardController@index`

```typescript
interface DashboardPageProps extends SharedProps {
    stats: {
        totalConversations: number
        activeConversations: number
        messagesThisWeek: number
        avgResponseTime: number
    }
    recentConversations: Array<{
        id: number
        customer: {
            name: string
            avatar_url: string | null
        }
        lastMessage: {
            content: string
            created_at: string
        }
        unread_count: number
    }>
    costAnalytics: {
        dailyCost: number
        weeklyCost: number
        monthlyCost: number
        breakdown: Array<{
            model: string
            cost: number
            tokens: number
        }>
    }
}
```

---

## Bot Pages

### Bots Index

**Route**: `GET /bots`
**Controller**: `Bot\BotController@index`

```typescript
interface BotsIndexPageProps extends SharedProps {
    bots: Paginated<{
        id: number
        name: string
        platform: 'facebook' | 'line' | 'telegram'
        status: 'active' | 'inactive' | 'paused'
        conversationCount: number
        lastActiveAt: string | null
        settings: {
            model: string
        } | null
    }>
    filters: {
        status?: string
        platform?: string
        search?: string
    }
}
```

### Bot Settings

**Route**: `GET /bots/{bot}/settings`
**Controller**: `Bot\BotSettingsController@show`

```typescript
interface BotSettingsPageProps extends SharedProps {
    bot: {
        id: number
        name: string
        platform: string
        status: string
        settings: {
            model: string
            temperature: number
            max_tokens: number
            system_prompt: string
            greeting_message: string
            fallback_message: string
        }
        limits: {
            max_messages_per_minute: number
            max_messages_per_hour: number
            max_tokens_per_message: number
        }
        hitlSettings: {
            enabled: boolean
            trigger_keywords: string[]
            auto_activate_after_minutes: number
        }
        responseHours: {
            enabled: boolean
            schedule: Record<string, { start: string; end: string }>
            outside_hours_message: string
        }
        aggregationSettings: {
            enabled: boolean
            window_seconds: number
            max_messages: number
        }
    }
    availableModels: Array<{
        id: string
        name: string
        provider: string
    }>
}
```

### Bot Edit (Connections)

**Route**: `GET /bots/{bot}/edit`
**Controller**: `Bot\BotController@edit`

```typescript
interface BotEditPageProps extends SharedProps {
    bot: {
        id: number
        name: string
        platform: string
        page_id: string
        // Credentials are NOT exposed to frontend
    }
    connections: Array<{
        id: number
        type: string
        status: 'connected' | 'disconnected' | 'error'
        lastVerifiedAt: string | null
    }>
}
```

---

## Chat Pages

### Chat Index

**Route**: `GET /chat`
**Controller**: `Chat\ChatController@index`

```typescript
interface ChatIndexPageProps extends SharedProps {
    conversations: CursorPaginated<{
        id: number
        customer: {
            id: number
            name: string
            avatar_url: string | null
        }
        lastMessage: {
            content: string
            sender_type: 'customer' | 'bot' | 'admin'
            created_at: string
        } | null
        unread_count: number
        is_hitl_active: boolean
        status: 'open' | 'closed'
    }>
    activeConversation: {
        id: number
        customer: {
            id: number
            name: string
            avatar_url: string | null
            metadata: Record<string, unknown>
        }
        messages: CursorPaginated<{
            id: number
            content: string
            sender_type: 'customer' | 'bot' | 'admin'
            metadata: Record<string, unknown>
            created_at: string
        }>
        is_hitl_active: boolean
        status: string
    } | null
    selectedBotId: number | null
}
```

---

## Flow Pages

### Flow Editor

**Route**: `GET /bots/{bot}/flows/{flow}/edit`
**Controller**: `Flow\FlowController@edit`

```typescript
interface FlowEditorPageProps extends SharedProps {
    bot: {
        id: number
        name: string
    }
    flow: {
        id: number
        name: string
        nodes: FlowNode[]
        edges: FlowEdge[]
        is_active: boolean
        version: number
        updated_at: string
    }
    availableNodes: Array<{
        type: string
        label: string
        description: string
        category: string
    }>
}

interface FlowNode {
    id: string
    type: string
    position: { x: number; y: number }
    data: Record<string, unknown>
}

interface FlowEdge {
    id: string
    source: string
    target: string
    sourceHandle?: string
    targetHandle?: string
}
```

---

## Knowledge Base Pages

### Knowledge Base Index

**Route**: `GET /bots/{bot}/knowledge-base`
**Controller**: `KnowledgeBase\KnowledgeBaseController@index`

```typescript
interface KnowledgeBasePageProps extends SharedProps {
    bot: {
        id: number
        name: string
    }
    knowledgeBase: {
        id: number
        name: string
        description: string
        embedding_model: string
        document_count: number
        chunk_count: number
    } | null
    documents: Paginated<{
        id: number
        filename: string
        status: 'pending' | 'processing' | 'completed' | 'failed'
        chunk_count: number
        file_size: number
        created_at: string
        error_message: string | null
    }>
}
```

---

## Evaluation Pages

### Evaluations Index

**Route**: `GET /evaluations`
**Controller**: `Evaluation\EvaluationController@index`

```typescript
interface EvaluationsPageProps extends SharedProps {
    evaluations: Paginated<{
        id: number
        name: string
        bot: {
            id: number
            name: string
        }
        status: 'draft' | 'running' | 'completed' | 'failed'
        test_case_count: number
        completed_count: number
        score: number | null
        created_at: string
    }>
}
```

### Evaluation Detail

**Route**: `GET /evaluations/{evaluation}`
**Controller**: `Evaluation\EvaluationController@show`

```typescript
interface EvaluationDetailPageProps extends SharedProps {
    evaluation: {
        id: number
        name: string
        status: string
        bot: {
            id: number
            name: string
        }
        testCases: Array<{
            id: number
            input: string
            expected_output: string
            actual_output: string | null
            score: number | null
            status: string
        }>
        report: {
            overall_score: number
            metrics: Record<string, number>
            summary: string
        } | null
    }
}
```

---

## Settings Pages

### Settings Index

**Route**: `GET /settings`
**Controller**: `Settings\SettingsController@index`

```typescript
interface SettingsPageProps extends SharedProps {
    settings: {
        theme: 'light' | 'dark' | 'system'
        language: string
        notifications_enabled: boolean
        email_notifications: boolean
    }
    team: Array<{
        id: number
        name: string
        email: string
        role: 'owner' | 'admin' | 'member'
        joined_at: string
    }>
}
```

---

## Utility Types

```typescript
interface Paginated<T> {
    data: T[]
    links: {
        first: string
        last: string
        prev: string | null
        next: string | null
    }
    meta: {
        current_page: number
        from: number
        last_page: number
        per_page: number
        to: number
        total: number
    }
}

interface CursorPaginated<T> {
    data: T[]
    next_cursor: string | null
    prev_cursor: string | null
    has_more: boolean
}
```

---

## Form Contracts

### useForm Types

```typescript
// Login Form
interface LoginForm {
    email: string
    password: string
    remember: boolean
}

// Bot Settings Form
interface BotSettingsForm {
    name: string
    model: string
    temperature: number
    max_tokens: number
    system_prompt: string
    greeting_message: string
    fallback_message: string
}

// Message Form
interface SendMessageForm {
    content: string
    attachments?: File[]
}

// Document Upload Form
interface DocumentUploadForm {
    files: File[]
}
```

---

## API Routes (Non-Inertia)

These routes remain as API endpoints (not Inertia pages):

| Route | Method | Purpose |
|-------|--------|---------|
| `/api/flows/{flow}/test` | POST | SSE streaming for flow testing |
| `/api/broadcasting/auth` | POST | WebSocket authentication |
| `/webhooks/facebook` | POST | Facebook webhook |
| `/webhooks/line` | POST | LINE webhook |
| `/webhooks/telegram` | POST | Telegram webhook |
