# Webhook Pipeline Refactor — Test Baseline
- Date: 2026-08-26 (+07)
- Commit: b97814aa865a2ac90f417f5bcec30b94b3ea8336
- Tests: 1153 passed, 0 failed, 13 skipped (2964 assertions)
- Command: `php artisan test` via php:8.4-cli-alpine Docker container (no host PHP), backend/ bind-mounted, vendor installed in-container.

Note: initial baseline attempt without a .env produced 1131 warnings (file_get_contents(/app/.env) failures) — fixed by copying .env.example to .env with generated APP_KEY. This file records the corrected, trustworthy baseline. All refactor tasks must keep: 1153 passed, 0 failed.
