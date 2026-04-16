# bot-fb Learning Index

## Source
- **Origin**: Local project (this repo itself)
- **GitHub**: https://github.com/JaoChai/bot-fb
- **Stack**: Laravel 12 + PHP 8.4 / React 19 + TypeScript / PostgreSQL+pgvector / Railway / Reverb / OpenRouter

## Explorations

### 2026-04-16 12:02 (--deep, 5 agents, ~4820 บรรทัด)

- [[2026-04-16/1202_ARCHITECTURE|Architecture]] — Directory, entry points, core abstractions, service graph (970 บรรทัด)
- [[2026-04-16/1202_CODE-SNIPPETS|Code Snippets]] — RAG pipeline, Stock double-inject, StockGuard, Zustand, Streaming hook (1616 บรรทัด)
- [[2026-04-16/1202_QUICK-REFERENCE|Quick Reference]] — Setup, daily commands, features, config, gotchas (697 บรรทัด)
- [[2026-04-16/1202_TESTING|Testing]] — PHPUnit + Vitest patterns, mocking, CI (876 บรรทัด)
- [[2026-04-16/1202_API-SURFACE|API Surface]] — 80+ REST endpoints, webhooks, WebSocket, jobs, external integrations (661 บรรทัด)

**Key insights**:
1. **Stock Management = double prompt injection** (header + footer) + **StockGuard 3-layer post-validation** — solves "conditional injection caused sales" incident (#124)
2. **RAG มี 10-step orchestration**: cache → intent → KB retrieval → complexity → memory → stock → prompt build → routing → generate → cache
3. **46 services** domain-organized + **FlowCacheService 30-min TTL** critical สำหรับลด DB load
4. **Frontend state แบ่งชัด**: Zustand (UI state + persist) vs React Query (server state + optimistic updates)
5. **Webhook ทั้ง 3 channels (LINE/Telegram/Facebook) async-first** ผ่าน Queue + ProcessWebhookJob pattern
