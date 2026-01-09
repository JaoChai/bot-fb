# Quickstart: Laravel Cloud Migration

**Feature Branch**: `007-laravel-cloud-migration`
**Date**: 2026-01-09

## Prerequisites

- PHP 8.4+
- Node.js 20+
- Composer 2.x
- Laravel Cloud account (Growth plan recommended)
- Access to current Neon database

---

## Phase 1: Install Inertia.js

### 1.1 Install Laravel Packages

```bash
cd backend

# Install Inertia server-side
composer require inertiajs/inertia-laravel

# Publish middleware
php artisan inertia:middleware
```

### 1.2 Register Middleware

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \App\Http\Middleware\HandleInertiaRequests::class,
    ]);
})
```

### 1.3 Install React Adapter

```bash
# Install Inertia React adapter
npm install @inertiajs/react

# Install Vite React plugin
npm install -D @vitejs/plugin-react
```

### 1.4 Configure Vite

```typescript
// vite.config.ts
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import react from '@vitejs/plugin-react'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
})
```

### 1.5 Create Root Blade Template

```blade
<!-- resources/views/app.blade.php -->
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
```

### 1.6 Create Inertia Entry Point

```tsx
// resources/js/app.tsx
import '../css/app.css'
import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'

createInertiaApp({
    title: (title) => `${title} - BotFacebook`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx')
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />)
    },
    progress: {
        color: '#4B5563',
    },
})
```

---

## Phase 2: Configure Shared Data

### 2.1 HandleInertiaRequests Middleware

```php
// app/Http/Middleware/HandleInertiaRequests.php
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => fn () => $request->user()
                    ? $request->user()->only('id', 'name', 'email')
                    : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'bots' => fn () => $request->user()
                ? $request->user()->bots()->select('id', 'name')->get()
                : [],
        ]);
    }
}
```

---

## Phase 3: Create First Inertia Page

### 3.1 Dashboard Controller

```php
// app/Http/Controllers/DashboardController.php
<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalConversations' => $user->bots()->withCount('conversations')->get()->sum('conversations_count'),
                'activeConversations' => $user->bots()->withCount(['conversations' => fn($q) => $q->where('status', 'open')])->get()->sum('conversations_count'),
                'messagesThisWeek' => 0, // Calculate from messages
                'avgResponseTime' => 0, // Calculate from metrics
            ],
            'recentConversations' => [],
            'costAnalytics' => [],
        ]);
    }
}
```

### 3.2 Dashboard Page Component

```tsx
// resources/js/Pages/Dashboard.tsx
import { Head } from '@inertiajs/react'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout'

interface DashboardProps {
    stats: {
        totalConversations: number
        activeConversations: number
        messagesThisWeek: number
        avgResponseTime: number
    }
}

export default function Dashboard({ stats }: DashboardProps) {
    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="grid grid-cols-4 gap-4">
                        <StatCard title="Total Conversations" value={stats.totalConversations} />
                        <StatCard title="Active" value={stats.activeConversations} />
                        <StatCard title="Messages This Week" value={stats.messagesThisWeek} />
                        <StatCard title="Avg Response Time" value={`${stats.avgResponseTime}s`} />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    )
}
```

### 3.3 Define Route

```php
// routes/web.php
use App\Http\Controllers\DashboardController;

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
```

---

## Phase 4: Migrate Components

### 4.1 Copy UI Components

```bash
# Copy from frontend to backend resources
cp -r frontend/src/components/ui/* backend/resources/js/Components/ui/
```

### 4.2 Update Imports

```tsx
// Before (frontend)
import { Button } from '@/components/ui/button'

// After (backend resources)
import { Button } from '@/Components/ui/button'
```

---

## Phase 5: Setup Echo/WebSocket

### 5.1 Copy Echo Configuration

```bash
cp frontend/src/lib/echo.ts backend/resources/js/Lib/echo.ts
```

### 5.2 Initialize in app.tsx

```tsx
// resources/js/app.tsx
import './Lib/echo' // Initialize Echo

createInertiaApp({
    // ... existing config
})
```

---

## Phase 6: Deploy to Laravel Cloud

### 6.1 Create Laravel Cloud Project

1. Go to [cloud.laravel.com](https://cloud.laravel.com)
2. Connect GitHub repository
3. Select `backend/` as root directory
4. Create new project

### 6.2 Create Serverless Postgres

1. In Laravel Cloud dashboard → Resources → Databases
2. Create Serverless Postgres
3. Enable pgvector extension (automatic with Neon backend)

### 6.3 Configure Environment Variables

```env
APP_ENV=production
APP_KEY=base64:...
APP_URL=https://your-app.laravel.cloud

DB_CONNECTION=pgsql
# (auto-injected by Laravel Cloud)

REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
```

### 6.4 Deploy

```bash
# Push to trigger deployment
git push origin main
```

---

## Verification Checklist

- [ ] Inertia.js installed and configured
- [ ] Dashboard page renders with props
- [ ] Authentication works (session-based)
- [ ] Echo WebSocket connects
- [ ] Laravel Cloud deployment successful
- [ ] Serverless Postgres connected
- [ ] pgvector extension available

---

## Troubleshooting

### "Page not found" in Inertia

Check that the page component exists at the correct path:
```tsx
// Must match: resources/js/Pages/Dashboard.tsx
Inertia::render('Dashboard', [...])
```

### Echo not connecting

Verify Reverb configuration in Laravel Cloud:
```php
// config/broadcasting.php
'reverb' => [
    'driver' => 'reverb',
    // Check credentials
]
```

### pgvector not working

Verify extension is enabled:
```sql
SELECT * FROM pg_extension WHERE extname = 'vector';
```

---

## Next Steps

After completing quickstart:

1. Run `/speckit.tasks` to generate detailed task breakdown
2. Follow migration phases in `plan.md`
3. Test each page after migration
4. Monitor production after deployment
