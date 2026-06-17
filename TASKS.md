# serverpulse/laravel-agent — Task Breakdown

---

## TASK-01: Project Scaffolding

| Field | Value |
|---|---|
| **Status** | ✅ Done |
| **Priority** | High |
| **Depends on** | None |
| **Estimated effort (manual)** | 1-2 hours |
| **Estimated effort (opencode)** | 15-30 min |

### Subtasks

#### 1.1 Initialize Package Repository
- Set up private Composer package repo
- Create directory structure per spec §6
- Initialize git with `.gitignore` (vendor, .phpunit.result.cache, etc.)
- **Deliverable:** Directory tree matching spec, git initialized

#### 1.2 composer.json
- Define package metadata: `serverpulse/laravel-agent`
- Set PHP `^8.1`, Laravel `^10.0|^11.0` constraints
- Dependencies: `illuminate/support`, `illuminate/console`, `illuminate/http`, `illuminate/scheduling`
- Dev: `pestphp/pest ^2.0`, `orchestra/testbench ^8.0|^9.0`
- Autoload PSR-4: `ServerPulse\Agent\` → `src/`
- Autoload-dev PSR-4: `ServerPulse\Agent\Tests\` → `tests/`
- `extra.laravel.providers` for auto-discovery
- **Deliverable:** `composer.json` complete and valid (`composer validate` passes)

#### 1.3 Test Setup
- `tests/Pest.php` with Orchestra Testbench base test case
- `phpunit.xml.dist` with coverage configuration
- Verify `./vendor/bin/pest` runs (even with zero tests)
- **Deliverable:** Pest runner functional

**Deliverable for TASK-01:** Scaffolded package, autoloaded, tests run green.

---

## TASK-02: ServerPulseServiceProvider

| Field | Value |
|---|---|
| **Status** | ✅ Done |
| **Priority** | High |
| **Depends on** | TASK-01 |
| **Estimated effort (manual)** | 2-3 hours |
| **Estimated effort (opencode)** | 30-45 min |

### Subtasks

#### 2.1 ServiceProvider — register()
- Singleton bindings for `ConfigService`, `ReportService`
- Tag all 9 collector classes as `serverpulse.collectors`
- **Deliverable:** Container resolves all tagged services

#### 2.2 ServiceProvider — boot()
- Register `ReportCommand::class` via `$this->commands()`
- Auto-register scheduler task (`serverpulse:report` everyMinute, withoutOverlapping 55) via `$this->callAfterResolving(Schedule::class, ...)`
- **Deliverable:** `php artisan schedule:list` shows `serverpulse:report`

#### 2.3 Auto-Discovery Verification
- `composer.json` `extra.laravel.providers` entry
- Verify provider auto-loaded in a fresh Laravel app without any manual config
- **Deliverable:** `php artisan` shows `serverpulse:report` command; scheduler entry present

#### 2.4 Integration Tests
- TestServiceProviderTest: assert bindings, scheduler registration
- **Deliverable:** Integration tests pass

**Deliverable for TASK-02:** Service provider fully functional, auto-discovered, tested.

---

## TASK-03: ConfigService (API-Driven Config with Caching)

| Field | Value |
|---|---|
| **Status** | ✅ Done |
| **Priority** | High |
| **Depends on** | TASK-01 |
| **Estimated effort (manual)** | 3-4 hours |
| **Estimated effort (opencode)** | 1-2 hours |

### Subtasks

#### 3.1 API_BASE Constant
- Define `API_BASE` constant in ConfigService (hardcoded `https://api.serverpulse.io`)
- **Deliverable:** Constant accessible, no need for `.env` or config file

#### 3.2 fetch() Method — Cache Check
- Read local cache file modification time
- If `< 300 seconds` old: return cached JSON as array
- **Requirement:** Cache file stored in `storage/framework/cache/serverpulse/.sp_cache` (Laravel) or `sys_get_temp_dir()` fallback
- **Deliverable:** Returns cached config within TTL without API call

#### 3.3 fetch() Method — API Call
- `GET /v1/agent/config` with domain in request body
- Headers: `X-Agent-Version: 1.0`
- Parse JSON response
- On 200: write cache file, return config array
- On 410: write `{ "enabled": false }` to cache, return disabled config
- On network/HTTP error: use stale cache (any age), else fallback defaults
- **Deliverable:** Fetches, caches, and returns config correctly for all HTTP scenarios

#### 3.4 Fallback Defaults
- Hardcoded sensible defaults per spec §5
- All collectors enabled by default
- Empty log_paths and git_paths
- **Deliverable:** Returns defaults when API unreachable and no cache exists

#### 3.5 Unit Tests — ConfigService
- Test: uses cache when fresh (< 5 min)
- Test: fetches from API when cache is stale
- Test: writes cache on successful API fetch
- Test: handles HTTP 410 (disables)
- Test: uses stale cache when API fails
- Test: uses fallback defaults when no cache and API down
- Test: respects 5-minute TTL boundary exactly
- Use `Http::fake()` for all API calls
- **Deliverable:** All ConfigService scenarios tested

**Deliverable for TASK-03:** ConfigService fully functional with caching, fallbacks, and tested.

---

## TASK-04: CollectorInterface & Base Patterns

| Field | Value |
|---|---|
| **Status** | ✅ Done |
| **Priority** | High |
| **Depends on** | TASK-01 |
| **Estimated effort (manual)** | 1-2 hours |
| **Estimated effort (opencode)** | 15-30 min |

### Subtasks

#### 4.1 CollectorInterface
- Contract: `public function collect(array $config): array`
- Returns associative array of metrics; empty array if nothing collected
- **Deliverable:** Interface defined

#### 4.2 Base Shell Exec Helper
- Trait or abstract base class for shell commands
- `escapeshellarg()` on all arguments
- Return null on failure (never throw)
- **Deliverable:** Shared shell safety across all shell-based collectors

#### 4.3 Error Handling Wrapper
- Common try/catch/log pattern for all collectors
- Catch exceptions, return empty array, never bubble up
- **Deliverable:** No collector can crash the ReportCommand

**Deliverable for TASK-04:** Shared collector infrastructure.

---

## TASK-05: ServerCollector

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | High |
| **Depends on** | TASK-04 |
| **Estimated effort (manual)** | 2-3 hours |
| **Estimated effort (opencode)** | 30-60 min |

### Subtasks

#### 5.1 CPU Collection
- `sys_getloadavg()` for load averages
- `/proc/cpuinfo` or `nproc` for core count
- Calculate `cpu_percent` = (load_avg_1m / cores) * 100
- **Deliverable:** CPU metrics accurate on Linux

#### 5.2 RAM Collection
- Parse `/proc/meminfo` for MemTotal, MemAvailable, MemFree
- Calculate used, percent
- Convert kB to MB
- **Deliverable:** RAM metrics match `free -m` output

#### 5.3 Disk Collection
- `disk_total_space('/')`, `disk_free_space('/')`
- Convert bytes to GB
- **Deliverable:** Disk metrics match `df -h` output

#### 5.4 Uptime Collection
- Read `/proc/uptime` for seconds
- **Deliverable:** Uptime matches `cat /proc/uptime`

#### 5.5 Unit Tests
- Mock `sys_getloadavg()`, filesystem, shell commands
- Test percentage calculations (boundary: load=0, load=cores, load=2*cores)
- Test RAM parsing with sample `/proc/meminfo` content
- Test disk math with simulated disk sizes
- **Deliverable:** ServerCollector fully tested in isolation

**Deliverable for TASK-05:** ServerCollector complete, tested.

---

## TASK-06: WebServerCollector

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | High |
| **Depends on** | TASK-04 |
| **Estimated effort (manual)** | 1-2 hours |
| **Estimated effort (opencode)** | 15-30 min |

### Subtasks

#### 6.1 Web Server Detection
- `pgrep -c nginx`, `pgrep -c apache2`, `pgrep -c httpd`
- Determine `server_type` (nginx/apache/null) and `running` boolean
- **Deliverable:** Correctly identifies running web server

#### 6.2 Active Connections
- Parse `ss -s` for estab count
- **Deliverable:** Reports established TCP count

#### 6.3 Unit Tests
- Mock shell responses for nginx running, apache running, none running
- Mock `ss -s` output (present, absent, malformed)
- **Deliverable:** WebServerCollector tested for all server types

**Deliverable for TASK-06:** WebServerCollector complete, tested.

---

## TASK-07: PhpCollector

| Field | Value |
|---|---|
| **Status** | ✅ Done |
| **Priority** | High |
| **Depends on** | TASK-04 |
| **Estimated effort (manual)** | 1-2 hours |
| **Estimated effort (opencode)** | 15-30 min |

### Subtasks

#### 7.1 PHP Info Collection
- `PHP_VERSION`, `PHP_MAJOR_VERSION`, `PHP_MINOR_VERSION`
- `get_loaded_extensions()` — filter to key extensions
- `ini_get('memory_limit')`, `ini_get('max_execution_time')`
- `function_exists('opcache_get_status')`
- **Deliverable:** PHP metrics match `php -i` output

#### 7.2 Unit Tests
- Test with fixed PHP version/ini values (no mocking needed for PHP constants)
- Test opcache enabled/disabled scenarios
- **Deliverable:** PhpCollector tested

**Deliverable for TASK-07:** PhpCollector complete, tested.

---

## TASK-08: DatabaseCollector

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | High |
| **Depends on** | TASK-04 |
| **Estimated effort (manual)** | 2-3 hours |
| **Estimated effort (opencode)** | 30-60 min |

### Subtasks

#### 8.1 MySQL Status Check
- `pgrep mysqld` / `pgrep mariadbd` for process check
- **Deliverable:** Reports MySQL running status

#### 8.2 Slow Queries (Conditional)
- Only if `mysql_credentials` present in config
- Connect via PDO, `SHOW GLOBAL STATUS LIKE 'Slow_queries'`
- 3-second timeout, catch all exceptions
- NEVER expose credentials in error output
- **Deliverable:** Slow query count reported when credentials provided; fails silently when not

#### 8.3 Laravel DB Config Reading
- Read from `config('database.connections')` for driver and connection names
- **Deliverable:** Reports configured DB driver and connection list

#### 8.4 Unit Tests
- Mock process check (running, not running)
- Mock PDO with test slow_queries value
- Test no-credentials scenario skips PDO entirely
- Test PDO connection failure returns gracefully
- Test reads Laravel DB config correctly
- **Deliverable:** DatabaseCollector tested

**Deliverable for TASK-08:** DatabaseCollector complete, tested.

---

## TASK-09: GitCollector

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | High |
| **Depends on** | TASK-04 |
| **Estimated effort (manual)** | 2-3 hours |
| **Estimated effort (opencode)** | 30-60 min |

### Subtasks

#### 9.1 Git Info Per Repo
- Iterate `git_paths` from config (from ConfigService)
- Per path: `cd {path} && git rev-parse --abbrev-ref HEAD` for branch
- `git log -1 --format=%H|%h|%s|%an|%cI` for commit info
- **Deliverable:** Returns structured git data per configured repo

#### 9.2 Error Handling
- If path doesn't exist or `.git` missing → `{ "error": "not_a_git_repo" }`
- If git commands fail → graceful `null` values
- **Deliverable:** Never crashes, reports errors per entry

#### 9.3 Path Safety
- `escapeshellarg()` on ALL paths
- `cd` to path before running git commands
- **Deliverable:** No shell injection possible

#### 9.4 Unit Tests
- Mock shell responses for valid repo, missing .git, empty branch
- Test multiple repos in git_paths
- Test shell escape safety
- **Deliverable:** GitCollector tested

**Deliverable for TASK-09:** GitCollector complete, tested.

---

## TASK-10: LogsCollector

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | High |
| **Depends on** | TASK-04 |
| **Estimated effort (manual)** | 2-3 hours |
| **Estimated effort (opencode)** | 30-60 min |

### Subtasks

#### 10.1 Log File Tailing
- Iterate `log_paths` from config
- Prefer `tail -n 50` via shell for efficiency
- PHP fallback: `SplFileObject` seek for environments without shell
- Return last 20 lines in payload
- **Deliverable:** Returns tail of each configured log file

#### 10.2 Error Counting
- Count lines matching `ERROR|CRITICAL|FATAL|EMERGENCY|Exception|Traceback` in tail
- **Deliverable:** Reports error count per log

#### 10.3 Size & Metadata
- `filesize()` for size in bytes
- Check readability before attempting tail
- **Deliverable:** Reports size and accessibility

#### 10.4 Error Handling
- File not found / not readable → `{ "error": "not_readable", "path": "..." }`
- Large file handling: shell `tail` is O(1), don't read entire file into memory
- **Deliverable:** Handles missing, unreadable, and huge files gracefully

#### 10.5 Unit Tests
- Mock filesystem with sample log content
- Test tail extraction (both shell and SplFileObject fallback)
- Test error pattern matching
- Test unreadable file handling
- **Deliverable:** LogsCollector tested

**Deliverable for TASK-10:** LogsCollector complete, tested.

---

## TASK-11: SecurityCollector

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | Medium |
| **Depends on** | TASK-04 |
| **Estimated effort (manual)** | 1-2 hours |
| **Estimated effort (opencode)** | 15-30 min |

### Subtasks

#### 11.1 SSH Failure Detection
- Check `/var/log/auth.log` (Debian/Ubuntu) and `/var/log/secure` (RHEL/CentOS)
- `grep 'Failed password' {log} | grep {current_hour} | wc -l`
- **Deliverable:** Reports failed SSH attempts in current hour

#### 11.2 Log File Detection
- Try auth.log first, then secure, then none
- Handle missing log files gracefully (return count 0)
- **Deliverable:** Works across distros without configuration

#### 11.3 Unit Tests
- Mock shell `grep` output with sample counts
- Test absent log files
- Test both log paths
- **Deliverable:** SecurityCollector tested

**Deliverable for TASK-11:** SecurityCollector complete, tested.

---

## TASK-12: LaravelCollector

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | High |
| **Depends on** | TASK-04 |
| **Estimated effort (manual)** | 4-5 hours |
| **Estimated effort (opencode)** | 1-2 hours |

### Subtasks

#### 12.1 Core Laravel Info
- `app()->environment()` → `app_env`
- `config('app.debug')` → `app_debug`
- `app()->version()` → `laravel_version`
- Hardcoded `"laravel"` → `php_framework`
- **Deliverable:** Basic Laravel environment info collected

#### 12.2 Queue Stats
- `config('queue.default')` → `queue_driver`
- Per-connection pending job counts (query queue driver — Redis `LLEN`, database count, etc.)
- Count from `failed_jobs` table
- **Deliverable:** Queue metrics per connection, failed job count

#### 12.3 Cache & Session
- `config('cache.default')` → `cache_driver`
- `config('session.driver')` → `session_driver`
- **Deliverable:** Cache and session driver info

#### 12.4 Horizon (Conditional)
- `class_exists(Laravel\Horizon\Horizon::class)` check
- If installed: get wait times, throughput from Horizon's internal API
- **Deliverable:** Horizon stats when available; gracefully absent when not

#### 12.5 Octane (Conditional)
- `class_exists(Laravel\Octane\Octane::class)` check
- **Deliverable:** Octane presence detected

#### 12.6 Exception Counting (via Monolog Buffer)
- Interface to read from Monolog handler's in-memory buffer
- Count exceptions logged since last cycle
- **Deliverable:** Recent exception count in each report

#### 12.7 Unit Tests
- Mock app environment, config, version
- Test queue driver introspection (mock each driver type)
- Test Horizon present/absent
- Test Octane present/absent
- **Deliverable:** LaravelCollector fully tested

**Deliverable for TASK-12:** LaravelCollector complete, tested.

---

## TASK-13: DomainCollector

| Field | Value |
|---|---|
| **Status** | ✅ Done |
| **Priority** | High |
| **Depends on** | TASK-04 |
| **Estimated effort (manual)** | 2-3 hours |
| **Estimated effort (opencode)** | 30-60 min |

### Subtasks

#### 13.1 Domain & Host Information
- `config('app.url')` → `app_url` (primary license identifier)
- `gethostname()` → `hostname`
- `$_SERVER['SERVER_ADDR']` or `gethostbyname(gethostname())` → `server_ip`
- **Deliverable:** Domain identity collected

#### 13.2 SSL Certificate Check
- Stream context to check SSL cert expiry on `app_url`
- Parse certificate valid_to date
- Handle non-HTTPS URLs gracefully
- **Deliverable:** SSL expiry date when applicable

#### 13.3 Unit Tests
- Mock `config('app.url')` with various URLs
- Mock `gethostname()` and server IP detection
- Mock SSL stream context (valid, expired, no HTTPS, connection error)
- **Deliverable:** DomainCollector tested

**Deliverable for TASK-13:** DomainCollector complete, tested.

---

## TASK-14: ReportService

| Field | Value |
|---|---|
| **Status** | ✅ Done |
| **Priority** | High |
| **Depends on** | TASK-03 |
| **Estimated effort (manual)** | 3-4 hours |
| **Estimated effort (opencode)** | 1-2 hours |

### Subtasks

#### 14.1 Payload Builder
- Accept config array from ConfigService
- Determine which collectors are enabled per `config['collect']`
- Build payload base: `timestamp`, `agent_ver`, `domain` (always included)
- Merge collector results under their keys
- `heartbeat: true` always
- **Deliverable:** Complete payload JSON matching spec §9 payload shape

#### 14.2 API Reporter
- `POST /v1/agent/report`
- Headers: `Content-Type: application/json`, `X-Agent-Version: 1.0`
- 15-second timeout, 5-second connect timeout
- **Deliverable:** Sends payload to API

#### 14.3 Response Handling
- 200/202: success, done
- 410: write `enabled=false` to cache file immediately (immediate disable)
- 4xx/5xx: log internally, discard payload for this cycle
- Network error: discard, retry next cycle
- **Deliverable:** All response codes handled per resilience spec §11

#### 14.4 Payload Size Guard
- Truncate payload if exceeds reasonable size (e.g., 500KB)
- Never send oversized payloads that could be rejected
- **Deliverable:** Payload size controlled

#### 14.5 Unit Tests
- Test payload structure matches API contract
- Test only enabled collectors included
- Test domain always present
- Test 202/410/500 responses with `Http::fake()`
- Test network timeout scenario
- Test immediate disable on 410
- **Deliverable:** ReportService fully tested

**Deliverable for TASK-14:** ReportService complete, tested.

---

## TASK-15: ReportCommand (Artisan Command)

| Field | Value |
|---|---|
| **Status** | ✅ Done |
| **Priority** | High |
| **Depends on** | TASK-03, TASK-14, TASK-05 through TASK-13 |
| **Estimated effort (manual)** | 3-4 hours |
| **Estimated effort (opencode)** | 1-2 hours |

### Subtasks

#### 15.1 Command Definition
- Signature: `serverpulse:report`
- Description: `Collect and report server metrics to ServerPulse`
- No arguments or options needed
- **Deliverable:** `php artisan serverpulse:report` available

#### 15.2 PID Lock File
- Check for lock file at cycle start
- If lock exists and < 55 seconds old → exit (another instance running)
- Write PID to lock file
- Register shutdown function to clean up lock file
- **Deliverable:** Concurrent runs prevented

#### 15.3 Execution Flow (handle() method)
```
1. Acquire PID lock
2. ConfigService::fetch() → config
3. if config.enabled == false → exit(0)
4. Resolve tagged collectors from container
5. For each enabled collector:
   - try { $collector->collect($config) } catch { skip, continue }
6. ReportService::send($payload)
7. Release lock
```
- **Deliverable:** Complete execution cycle per spec §4

#### 15.4 Security: No Output Leakage
- All output suppressed in production
- Verbose mode (`-v`) logs internal steps for debugging
- Never dump API responses, config, or payloads in default output
- **Deliverable:** Silent in production; verbose option for debugging

#### 15.5 Feature Tests (End-to-End)
- Test full cycle: mock config API, mock report API, verify payload
- Test disabled agent exits without reporting
- Test 410 during config fetch disables agent
- Test 410 during report send disables agent
- Test collector exception doesn't break cycle
- Test PID lock prevents overlap
- **Deliverable:** ReportCommand E2E tested

**Deliverable for TASK-15:** ReportCommand complete, tested.

---

## TASK-16: RequestTaggingMiddleware

| Field | Value |
|---|---|
| **Status** | ✅ Done |
| **Priority** | Medium |
| **Depends on** | TASK-02 |
| **Estimated effort (manual)** | 2-3 hours |
| **Estimated effort (opencode)** | 30-60 min |

### Subtasks

#### 16.1 Request Counter
- Increment atomic counter per request
- Thread-safe (file-based or in-memory)
- Reset every 60 seconds (use timestamp-based bucketing)
- **Deliverable:** Reports `request_count_1m`

#### 16.2 Response Time Tracker
- Start timer at request begin
- Record elapsed time at request end
- Maintain rolling average for last minute
- **Deliverable:** Reports `response_time_avg_1m`

#### 16.3 Middleware Registration
- Auto-register globally via ServiceProvider
- or allow opt-in via `'serverpulse'` middleware alias
- Minimal overhead: simple counter + timer, no DB, no redis
- **Deliverable:** Middleware active, minimal performance impact

#### 16.4 Performance Guard
- Entire middleware must execute in < 0.1ms
- Use `hrtime()` for high-precision timing
- No I/O in middleware (defer writes to collector)
- **Deliverable:** Negligible overhead confirmed

#### 16.5 Unit Tests
- Test counter increments
- Test average response time calculation
- Test counter is read by LaravelCollector correctly
- **Deliverable:** Middleware tested

**Deliverable for TASK-16:** Request tagging middleware complete, tested.

---

## TASK-17: ServerPulseHandler (Monolog)

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | Medium |
| **Depends on** | TASK-02 |
| **Estimated effort (manual)** | 1-2 hours |
| **Estimated effort (opencode)** | 15-30 min |

### Subtasks

#### 17.1 Monolog Handler Implementation
- Extend `Monolog\Handler\AbstractProcessingHandler`
- Buffer exception counts in memory (circular buffer, last N entries)
- Expose `getRecentExceptionCount(): int` for LaravelCollector
- **Deliverable:** Exception count available per cycle

#### 17.2 Handler Registration
- Register in ServiceProvider::boot() via `Log::getLogger()->pushHandler()`
- Or register as a custom Monolog channel
- Catch any registration failures silently
- **Deliverable:** Handler capturing exceptions

#### 17.3 Unit Tests
- Test handler captures log records
- Test exception count resets between reads
- Test non-exception log entries ignored
- **Deliverable:** Handler tested

**Deliverable for TASK-17:** Monolog handler complete, tested.

---

## TASK-18: ServerPulse Facade

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | Low |
| **Depends on** | TASK-02, TASK-15 |
| **Estimated effort (manual)** | 1 hour |
| **Estimated effort (opencode)** | 10-15 min |

### Subtasks

#### 18.1 Facade Class
- `ServerPulse\Agent\Facades\ServerPulse`
- Accessor: `serverpulse` or `ServerPulse\Agent\ServerPulseServiceProvider`
- Methods: `enable()`, `disable()`, `report()` for programmatic control
- **Deliverable:** `ServerPulse::disable()` works

#### 18.2 Unit Tests
- Test facade resolves underlying service
- Test enable/disable toggles
- Test manual `ServerPulse::report()` triggers collection
- **Deliverable:** Facade tested

**Deliverable for TASK-18:** Facade complete, tested.

---

## TASK-19: Integration & E2E Testing Suite

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | High |
| **Depends on** | TASK-05 through TASK-18 |
| **Estimated effort (manual)** | 4-5 hours |
| **Estimated effort (opencode)** | 2-3 hours |

### Subtasks

#### 19.1 Full Integration Test with Testbench
- Spin up simulated Laravel app
- Configure all `Http::fake()` responses
- Mock all shell commands and filesystem
- Run `serverpulse:report` command
- Assert final payload matches expected shape
- **Deliverable:** Full cycle passes in tests

#### 19.2 Resilience Scenario Tests
- API unreachable → stale cache used
- API unreachable + no cache → defaults used
- 410 on config → agent disables
- 410 on report → agent disables immediately
- Collector throws → other collectors still run
- Scheduler + direct cron both active → no overlap (withoutOverlapping)
- **Deliverable:** All resilience scenarios tested

#### 19.3 Fresh Laravel App Test
- Install package into a clean Laravel 10 or 11 app
- Verify auto-discovery works
- Verify `php artisan` lists `serverpulse:report`
- Verify `php artisan schedule:list` shows the task
- **Deliverable:** Package works in a real Laravel app

#### 19.4 PHP Version Matrix Test
- Test on PHP 8.1, 8.2, 8.3
- Test on Laravel 10, Laravel 11
- **Deliverable:** Compatibility confirmed

**Deliverable for TASK-19:** Comprehensive test suite passing across PHP/Laravel versions.

---

## TASK-20: Documentation & Release

| Field | Value |
|---|---|
| **Status** | ⬜ Pending |
| **Priority** | Medium |
| **Depends on** | All tasks |
| **Estimated effort (manual)** | 2-3 hours |
| **Estimated effort (opencode)** | 15-30 min |

### Subtasks

#### 20.1 README.md
- Installation: `composer require` + scheduling options
- Requirements: PHP 8.1+, Laravel 10+
- What it does (monitoring + license tracking)
- Nothing for end-user to do
- Cron setup for Option B
- **Deliverable:** Clear README

#### 20.2 CHANGELOG.md
- v1.0.0 initial release notes
- **Deliverable:** Changelog started

#### 20.3 Version Tagging
- Tag `v1.0.0` on git
- Push to private packagist/composer repository
- **Deliverable:** Package installable via `composer require`

**Deliverable for TASK-20:** Package released and documented.

---

## Dependency Graph

```
TASK-01 (Scaffolding)
  ├── TASK-02 (ServiceProvider)
  │     ├── TASK-16 (Middleware)
  │     └── TASK-17 (Monolog)
  ├── TASK-03 (ConfigService)
  ├── TASK-04 (CollectorInterface)
  │     ├── TASK-05 (ServerCollector)
  │     ├── TASK-06 (WebServerCollector)
  │     ├── TASK-07 (PhpCollector)
  │     ├── TASK-08 (DatabaseCollector)
  │     ├── TASK-09 (GitCollector)
  │     ├── TASK-10 (LogsCollector)
  │     ├── TASK-11 (SecurityCollector)
  │     ├── TASK-12 (LaravelCollector)
  │     └── TASK-13 (DomainCollector)
  ├── TASK-14 (ReportService) ← depends on TASK-03
  └── TASK-15 (ReportCommand) ← depends on TASK-03, TASK-14, TASK-05→13
        └── TASK-18 (Facade)
              └── TASK-19 (Integration Tests)
                    └── TASK-20 (Documentation & Release)
```

---

## Summary

| Total Tasks | 20 |
|---|---|
| Total Subtasks | ~50 |
| Estimated total effort (manual) | 40-55 hours |
| **Estimated total effort (opencode)** | **12-20 hours** |
| Phase 1 (Core): TASK-01 through TASK-04, TASK-14, TASK-15 | Foundation + command cycle |
| Phase 2 (Collectors): TASK-05 through TASK-13 | All metric collection |
| Phase 3 (Integration): TASK-16 through TASK-19 | Middleware, Monolog, Facade, Testing |
| Phase 4 (Release): TASK-20 | Docs and publish |
