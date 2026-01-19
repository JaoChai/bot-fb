# Deployment Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 13:03

## Table of Contents

**Total Rules: 18**

- [Railway Ops](#railway) - 6 rules (1 CRITICAL)
- [Environment](#env) - 4 rules (1 CRITICAL)
- [Health Checks](#health) - 3 rules (2 HIGH)
- [Rollback](#rollback) - 2 rules (1 CRITICAL)
- [Troubleshooting](#troubleshoot) - 3 rules (1 CRITICAL)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Runtime failures, data loss, security issues |
| **HIGH** | UX degradation, performance issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have, minor improvements |

## Railway Ops
<a name="railway"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [railway-001-deploy-failure](rules/railway-001-deploy-failure.md) | **CRITICAL** | Railway Deployment Failure |
| [railway-002-build-error](rules/railway-002-build-error.md) | **HIGH** | Build Phase Errors |
| [railway-003-log-analysis](rules/railway-003-log-analysis.md) | **HIGH** | Log Analysis and Filtering |
| [railway-004-service-config](rules/railway-004-service-config.md) | MEDIUM | Service Configuration Issues |
| [railway-005-domain-setup](rules/railway-005-domain-setup.md) | MEDIUM | Domain and SSL Configuration |
| [railway-006-scaling](rules/railway-006-scaling.md) | LOW | Scaling and Resource Management |

## Environment
<a name="env"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [env-001-required-vars](rules/env-001-required-vars.md) | **CRITICAL** | Missing Required Environment Variables |
| [env-002-secrets-management](rules/env-002-secrets-management.md) | **HIGH** | Secrets Management Best Practices |
| [env-003-config-sync](rules/env-003-config-sync.md) | MEDIUM | Environment Configuration Sync Issues |
| [env-004-env-mismatch](rules/env-004-env-mismatch.md) | MEDIUM | Environment Mismatch Issues |

## Health Checks
<a name="health"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [health-001-endpoint-config](rules/health-001-endpoint-config.md) | **HIGH** | Health Endpoint Configuration |
| [health-002-component-checks](rules/health-002-component-checks.md) | **HIGH** | Component Health Checks |
| [health-003-monitoring-setup](rules/health-003-monitoring-setup.md) | MEDIUM | Health Monitoring and Alerting Setup |

## Rollback
<a name="rollback"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [rollback-001-procedure](rules/rollback-001-procedure.md) | **CRITICAL** | Emergency Rollback Procedure |
| [rollback-002-database-migration](rules/rollback-002-database-migration.md) | **HIGH** | Database Migration Rollback |

## Troubleshooting
<a name="troubleshoot"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [troubleshoot-001-500-errors](rules/troubleshoot-001-500-errors.md) | **CRITICAL** | Debugging 500 Internal Server Errors |
| [troubleshoot-002-slow-response](rules/troubleshoot-002-slow-response.md) | **HIGH** | Debugging Slow Response Times |
| [troubleshoot-003-connection-issues](rules/troubleshoot-003-connection-issues.md) | **HIGH** | Connection and Network Issues |

## Quick Reference by Tag

- **500**: troubleshoot-001-500-errors
- **alerting**: health-003-monitoring-setup
- **analysis**: railway-003-log-analysis
- **api-keys**: env-002-secrets-management
- **build**: railway-002-build-error
- **cache**: health-002-component-checks
- **components**: health-002-component-checks
- **composer**: railway-002-build-error
- **config**: railway-004-service-config, env-001-required-vars, env-003-config-sync
- **connection**: troubleshoot-003-connection-issues
- **credentials**: env-002-secrets-management
- **database**: troubleshoot-003-connection-issues, rollback-002-database-migration, health-002-component-checks
- **debugging**: railway-003-log-analysis, env-004-env-mismatch, troubleshoot-001-500-errors, troubleshoot-002-slow-response
- **deploy**: railway-001-deploy-failure
- **dns**: railway-005-domain-setup
- **domain**: railway-005-domain-setup
- **emergency**: rollback-001-procedure
- **endpoint**: health-001-endpoint-config
- **environment**: env-001-required-vars, env-003-config-sync, env-004-env-mismatch
- **error**: railway-002-build-error, troubleshoot-001-500-errors
- **failure**: railway-001-deploy-failure
- **health**: health-001-endpoint-config, health-002-component-checks
- **latency**: troubleshoot-002-slow-response
- **local**: env-003-config-sync, env-004-env-mismatch
- **logs**: railway-003-log-analysis
- **migration**: rollback-002-database-migration
- **mismatch**: env-004-env-mismatch
- **monitoring**: health-001-endpoint-config, health-003-monitoring-setup
- **network**: troubleshoot-003-connection-issues
- **npm**: railway-002-build-error
- **performance**: railway-006-scaling, troubleshoot-002-slow-response
- **production**: railway-001-deploy-failure, env-001-required-vars, env-003-config-sync, env-004-env-mismatch, troubleshoot-001-500-errors, rollback-001-procedure
- **queue**: health-002-component-checks
- **railway**: railway-001-deploy-failure, railway-002-build-error, railway-003-log-analysis, railway-004-service-config, railway-005-domain-setup, railway-006-scaling, health-001-endpoint-config
- **recovery**: rollback-001-procedure
- **resources**: railway-006-scaling
- **rollback**: rollback-001-procedure, rollback-002-database-migration
- **scaling**: railway-006-scaling
- **schema**: rollback-002-database-migration
- **secrets**: env-002-secrets-management
- **security**: env-002-secrets-management
- **sentry**: health-003-monitoring-setup
- **service**: railway-004-service-config
- **settings**: railway-004-service-config
- **slow**: troubleshoot-002-slow-response
- **ssl**: railway-005-domain-setup
- **sync**: env-003-config-sync
- **timeout**: troubleshoot-003-connection-issues
- **uptime**: health-003-monitoring-setup
- **variables**: env-001-required-vars
