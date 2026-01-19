---
id: ws-005-ping-pong-timeout
title: WebSocket Ping/Pong Timeout
impact: LOW
impactDescription: "Connections dropped due to inactivity"
category: ws
tags: [websocket, ping, pong, timeout, keepalive]
relatedRules: [ws-001-connection-drop, ws-002-auth-failure]
---

## Symptom

- Connections drop after period of inactivity
- Works fine with active messaging
- Disconnects during idle periods
- Mobile apps disconnect in background

## Root Cause

1. Ping interval too long
2. Pong timeout too short
3. Load balancer timeout shorter than ping interval
4. NAT timeout closing idle connections
5. Mobile OS suspending background connections

## Diagnosis

### Quick Check

```javascript
// Check ping/pong in browser dev tools
// Network tab > WS > filter by socket
// Look for ping/pong frames

// Echo ping status
Echo.connector.pusher.connection.bind('ping', () => {
    console.log('Ping sent at', new Date());
});
Echo.connector.pusher.connection.bind('pong', () => {
    console.log('Pong received at', new Date());
});
```

### Detailed Analysis

```bash
# Check Reverb ping configuration
grep -r "ping" config/reverb.php

# Monitor connection lifecycles
tail -f storage/logs/reverb.log | grep -E "ping|pong|timeout|disconnect"
```

## Solution

### Fix Steps

1. **Configure Reverb Ping Settings**
```php
// config/reverb.php
'apps' => [
    [
        'app_id' => env('REVERB_APP_ID'),
        'app_key' => env('REVERB_APP_KEY'),
        'app_secret' => env('REVERB_APP_SECRET'),
        'options' => [
            'ping_interval' => 25,      // Send ping every 25 seconds
            'pong_timeout' => 10,       // Wait 10 seconds for pong response
            'activity_timeout' => 120,  // Close after 2 minutes of no activity
        ],
    ],
],
```

2. **Configure Load Balancer Timeout**
```nginx
# nginx configuration
location /app {
    proxy_pass http://reverb:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600;      # 1 hour
    proxy_send_timeout 3600;
    proxy_connect_timeout 60;
}
```

3. **Client-Side Heartbeat**
```javascript
// Manual heartbeat for mobile/background
let heartbeatInterval;

Echo.connector.pusher.connection.bind('connected', () => {
    // Start heartbeat
    heartbeatInterval = setInterval(() => {
        if (Echo.connector.pusher.connection.state === 'connected') {
            Echo.connector.pusher.send_event('pusher:ping', {});
        }
    }, 20000); // Every 20 seconds
});

Echo.connector.pusher.connection.bind('disconnected', () => {
    clearInterval(heartbeatInterval);
});
```

### Code Example

```typescript
// Good: Robust connection with heartbeat management
class WebSocketHeartbeat {
    private echo: Echo;
    private heartbeatTimer: NodeJS.Timer | null = null;
    private lastPong: number = Date.now();
    private readonly pingInterval = 25000; // 25 seconds
    private readonly pongTimeout = 10000;  // 10 seconds

    constructor(echo: Echo) {
        this.echo = echo;
        this.setupHeartbeat();
    }

    private setupHeartbeat(): void {
        const connection = this.echo.connector.pusher.connection;

        connection.bind('connected', () => {
            this.startHeartbeat();
        });

        connection.bind('disconnected', () => {
            this.stopHeartbeat();
        });

        connection.bind('pong', () => {
            this.lastPong = Date.now();
        });
    }

    private startHeartbeat(): void {
        this.stopHeartbeat();

        this.heartbeatTimer = setInterval(() => {
            this.sendPing();
            this.checkPong();
        }, this.pingInterval);
    }

    private stopHeartbeat(): void {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    }

    private sendPing(): void {
        try {
            this.echo.connector.pusher.send_event('pusher:ping', {});
        } catch (e) {
            console.error('Failed to send ping:', e);
        }
    }

    private checkPong(): void {
        const timeSinceLastPong = Date.now() - this.lastPong;

        if (timeSinceLastPong > this.pingInterval + this.pongTimeout) {
            console.warn('Pong timeout - reconnecting');
            this.echo.connector.pusher.connection.reconnect();
        }
    }

    // Call when app goes to foreground
    onForeground(): void {
        if (this.echo.connector.pusher.connection.state !== 'connected') {
            this.echo.connector.pusher.connect();
        }
        this.startHeartbeat();
    }

    // Call when app goes to background
    onBackground(): void {
        // Optionally reduce ping frequency or stop
        this.stopHeartbeat();
    }
}

// Usage with visibility API
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        heartbeat.onForeground();
    } else {
        heartbeat.onBackground();
    }
});
```

## Prevention

- Set ping interval < load balancer timeout
- Use appropriate timeouts for use case
- Handle visibility/foreground events
- Monitor connection health metrics
- Document timeout requirements

## Debug Commands

```bash
# Check current Reverb config
php artisan config:show reverb

# Test connection keepalive
# In browser console:
const start = Date.now();
Echo.connector.pusher.connection.bind('disconnected', () => {
    console.log('Disconnected after', (Date.now() - start) / 1000, 'seconds');
});

# Check Railway/load balancer timeout
# In Railway dashboard, check service settings
```

## Project-Specific Notes

**BotFacebook Context:**
- Default ping interval: 25 seconds
- Railway timeout: 60 seconds (configured in Procfile)
- Mobile app uses reduced heartbeat in background
- Reconnection handled by `useConnectionStore`
