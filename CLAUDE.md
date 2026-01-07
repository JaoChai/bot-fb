# BotFacebook

## Stack
- Laravel 12 + React 19 + PostgreSQL (Neon)
- Railway (deploy) + Reverb (WebSocket)

## URLs
| Service | URL |
|---------|-----|
| Frontend | https://www.botjao.com |
| Backend | https://api.botjao.com |

## Gotchas
| Problem | Fix |
|---------|-----|
| `config('x','')` returns null | Use `config('x') ?? ''` |
| API response wrapped in `{data:X}` | Access `response.data` |
| Railway serve.json fails | Use Express server |

## When Debugging
- Search memory first for similar bugs
- Use MCP `diagnose` tool for system health check
