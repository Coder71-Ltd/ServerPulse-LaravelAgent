# serverpulse/laravel-agent

ServerPulse monitoring and license agent for Laravel applications. A zero-config, headless Composer package that collects server and application metrics every 60 seconds and reports them to the central ServerPulse API.

## Philosophy

| Decision | Rationale |
|----------|-----------|
| No `.env` vars | End-user never touches environment |
| No config file | Nothing to publish or misconfigure |
| No API key | Backend identifies by reported `app_url` |
| No `artisan install` | Nothing to prompt or run |
| No routes/views | Zero footprint in the host app |
| No DB migrations | File-based cache only (`.sp_cache`) |
| `composer require` only | Single step to integrate |

## Architecture

```
artisan serverpulse:report (every 60s)
  ├── ConfigService::get()
  │     ├── Cache hit (<300s) → return cached config
  │     ├── Cache stale → GET /v1/agent/config
  │     └── Network error → stale cache or fallback defaults
  ├── 9 Collectors (run only if enabled in config)
  │     ├── ServerCollector          — CPU, RAM, disk, uptime
  │     ├── WebServerCollector       — nginx/apache, connections
  │     ├── PhpCollector             — version, extensions, ini
  │     ├── DatabaseCollector        — MySQL status, slow queries
  │     ├── GitCollector             — branch, commit per repo
  │     ├── LogsCollector            — tail, error count per log
  │     ├── SecurityCollector        — failed SSH logins
  │     ├── LaravelCollector         — env, queue, Horizon, exceptions
  │     └── DomainCollector          — app_url, hostname, SSL expiry
  └── ReportService::send()
        └── POST /v1/agent/report
```

## Design Decision: Dynamic API_BASE

**Problem:** If the ServerPulse API migrates to a new domain, agents hardcoded with the old URL can never reach the new endpoint.

**Solution:** The `GET /v1/agent/config` endpoint can optionally include an `api_base_url` field in its response. `ConfigService` stores this in the cache file and uses it for all subsequent API calls, overriding the configured `api_base_url`.

```
First run:
  → Config api_base_url = 'https://api.serverpulse.io'
  → GET https://api.serverpulse.io/v1/agent/config
  → Response: { ..., "api_base_url": "https://new-api.example.com" }
  → Cached: { ..., "__api_base_url": "https://new-api.example.com" }

Subsequent runs:
  → resolveApiBase() reads cache → 'https://new-api.example.com'
  → All API calls now target the new domain
```

**Key behaviors:**
- `config('services.serverpulse.api_base')` is the bootstrap default
- `resolveApiBase()` checks cache for `__api_base_url` → falls back to config value
- Server can migrate all agents progressively as they refresh config every 5 minutes
- Zero client interaction required

## Development

### Commands

```bash
composer install                    # install dependencies
vendor/bin/pest --compact           # run all tests
vendor/bin/pest --compact --filter=ConfigServiceTest  # run specific test
vendor/bin/phpstan analyse          # static analysis (level 7)
vendor/bin/pint --dirty --format agent   # code formatting
```

### TDD Workflow

All code follows Red → Green → Refactor:

1. **RED** — Write a failing test that describes the desired behavior
2. **GREEN** — Write the minimum code to make it pass
3. **REFACTOR** — Clean up; tests must still pass

### Package Structure

```
├── src/
│   ├── ServerPulseServiceProvider.php
│   ├── Console/Commands/
│   │   └── ReportCommand.php
│   ├── Collectors/
│   │   ├── Contracts/CollectorInterface.php
│   │   └── [9 collector implementations]
│   ├── Services/
│   │   ├── ConfigService.php
│   │   └── ReportService.php
│   ├── Middleware/
│   ├── Monolog/
│   └── Facades/
├── config/
│   └── serverpulse.php
├── tests/
│   ├── Unit/
│   │   ├── Services/
│   │   └── Collectors/
│   └── Feature/
├── composer.json
├── phpunit.xml
├── phpstan.neon
├── pint.json
└── TASKS.md
```

### Progress

Tracked in `TASKS.md`.
