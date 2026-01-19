---
id: ws-001-connection-drop
title: WebSocket Connection Drops
impact: HIGH
impactDescription: "Real-time updates stop working, users see stale data"
category: ws
tags: [websocket, reverb, connection, real-time]
relatedRules: [ws-002-auth-failure, ws-005-ping-pong-timeout]
---

## Symptom

- Real-time messages stop appearing
- Connection state shows "disconnected"
- Console shows "WebSocket is closed"
- Intermittent connectivity

## Root Cause

1. Reverb server crashed or not running
2. Network timeout (NAT/firewall)
3. Server-side ping/pong timeout
4. SSL certificate issues
5. Load balancer dropping idle connections

## Diagnosis

### Quick Check

```javascript
// Check connection state in browser console
Echo.connector.pusher.connection.state
// Should be: 'connected'
// Problem states: 'disconnected', 'connecting', 'unavailable'

// Check connection events
Echo.connector.pusher.connection.bind('state_change', (states) => {
    console.log('Connection state changed:', states);
});
```

### Detailed Analysis

```bash
# Check if Reverb is running
ps aux | grep reverb

# Check Reverb logs
tail -f storage/logs/reverb.log

# Test WebSocket directly
wscat -c wss://api.botjao.com/app/your-key?protocol=7
```

## Solution

### Fix Steps

1. **Ensure Reverb is Running**
```bash
# Start Reverb
php artisan reverb:start

# Or with supervisor (production)
# /etc/supervisor/conf.d/reverb.conf
[program:reverb]
command=php /var/www/html/artisan reverb:start
autostart=true
autorestart=true
```

2. **Configure Ping/Pong**
```php
// config/reverb.php
'apps' => [
    [
        'app_id' => env('REVERB_APP_ID'),
        'ping_interval' => 25,      // Send ping every 25 seconds
        'pong_timeout' => 10,       // Wait 10 seconds for pong
        'activity_timeout' => 120,  // Close after 2 min inactivity
    ],
],
```

3. **Client-Side Reconnection**
```javascript
// Configure Echo with reconnection
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: process.env.REVERB_APP_KEY,
    wsHost: process.env.REVERB_HOST,
    wsPort: process.env.REVERB_PORT,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
    // Auto reconnect
    disableStats: true,
    enableLogging: true,
});

// Manual reconnection logic
let reconnectAttempts = 0;
const maxReconnectAttempts = 5;

Echo.connector.pusher.connection.bind('disconnected', () => {
    if (reconnectAttempts < maxReconnectAttempts) {
        setTimeout(() => {
            reconnectAttempts++;
            Echo.connector.pusher.connect();
        }, Math.min(1000 * Math.pow(2, reconnectAttempts), 30000));
    }
});

Echo.connector.pusher.connection.bind('connected', () => {
    reconnectAttempts = 0;
});
```

### Code Example

```typescript
// Good: Robust WebSocket connection management
class WebSocketManager {
    private echo: Echo;
    private reconnectAttempts = 0;
    private readonly maxReconnectAttempts = 5;
    private subscriptions: Map<string, any> = new Map();

    constructor() {
        this.echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT,
            forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        });

        this.setupConnectionHandlers();
    }

    private setupConnectionHandlers(): void {
        const connection = this.echo.connector.pusher.connection;

        connection.bind('connected', () => {
            console.log('WebSocket connected');
            this.reconnectAttempts = 0;
            this.resubscribeAll();
        });

        connection.bind('disconnected', () => {
            console.log('WebSocket disconnected');
            this.scheduleReconnect();
        });

        connection.bind('error', (error: any) => {
            console.error('WebSocket error:', error);
        });
    }

    private scheduleReconnect(): void {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            console.error('Max reconnection attempts reached');
            // Notify user to refresh
            return;
        }

        const delay = Math.min(
            1000 * Math.pow(2, this.reconnectAttempts),
            30000
        );

        setTimeout(() => {
            this.reconnectAttempts++;
            console.log(`Reconnecting (attempt ${this.reconnectAttempts})...`);
            this.echo.connector.pusher.connect();
        }, delay);
    }

    private resubscribeAll(): void {
        this.subscriptions.forEach((callback, channel) => {
            this.subscribe(channel, callback);
        });
    }

    subscribe(channel: string, callback: (data: any) => void): void {
        this.subscriptions.set(channel, callback);
        this.echo.private(channel).listen('.message', callback);
    }

    unsubscribe(channel: string): void {
        this.subscriptions.delete(channel);
        this.echo.leave(channel);
    }
}
```

## Prevention

- Use supervisor for Reverb in production
- Configure appropriate ping intervals
- Implement client-side reconnection
- Monitor WebSocket health
- Use connection status indicator in UI

## Debug Commands

```bash
# Check Reverb process
ps aux | grep reverb

# Check Reverb port
netstat -tlnp | grep 8080

# Test WebSocket connection
wscat -c "wss://api.botjao.com/app/your-app-key"

# Check Reverb logs
tail -100 storage/logs/reverb.log
```

## Project-Specific Notes

**BotFacebook Context:**
- Reverb config: `config/reverb.php`
- Echo setup: `frontend/src/lib/echo.ts`
- Channels: `private-bot.{botId}`, `private-conversation.{id}`
- Connection status shown in UI header
