# Webhook Pipeline Refactor — Test Baseline
- Date: 2026-08-26 (+07)
- Commit: b97814aa865a2ac90f417f5bcec30b94b3ea8336
- Tests: 1166, Passed: 35, Failed: 0, Skipped: 0
- Warnings: 1131, Assertions: 2964

Note: no local PHP on host — suite run via `php:8.4-cli-alpine` Docker image with backend/ bind-mounted.
phpunit.xml forces sqlite :memory: (testing env), so no external DB needed.
