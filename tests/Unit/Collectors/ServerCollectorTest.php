<?php

use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Collectors\ServerCollector;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Assert that the result array contains all 12 expected server metric keys.
 */
function assertServerKeys(array $result): void
{
    expect($result)->toHaveKeys([
        'cpu_percent',
        'load_avg_1m',
        'load_avg_5m',
        'load_avg_15m',
        'cpu_cores',
        'ram_total_mb',
        'ram_used_mb',
        'ram_percent',
        'disk_total_gb',
        'disk_used_gb',
        'disk_percent',
        'uptime_seconds',
    ]);
}

/**
 * Create a ServerCollector anonymous subclass that exercises the REAL
 * collectMetrics() math while injecting fixture data through the source
 * hooks (safeParseProcFile / safeFileGet / safeExec).
 *
 * This bypasses the D-10 (PHP_OS_FAMILY) guard only — all conversion and
 * calculation logic is the production code path.
 */
function makeServerCollector(array $procFiles = [], array $files = [], array $shell = []): ServerCollector
{
    return new class($procFiles, $files, $shell) extends ServerCollector
    {
        public function __construct(
            private readonly array $procFiles,
            private readonly array $files,
            private readonly array $shell,
        ) {}

        protected function safeParseProcFile(string $path): ?array
        {
            return $this->procFiles[$path] ?? null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return $this->files[$path] ?? null;
        }

        protected function safeExec(string $command): ?string
        {
            return $this->shell[$command] ?? null;
        }

        protected function doCollect(array $config): array
        {
            // D-10 bypass only — the metric math is the production code path.
            return $this->collectMetrics();
        }
    };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('returns all metric keys in response', function () {
    $result = makeServerCollector()->collect([]);

    assertServerKeys($result);
});

it('returns nulls for /proc sources when no data is available', function () {
    $result = makeServerCollector()->collect([]);

    assertServerKeys($result);

    // /proc-dependent metrics return null when no fixture is provided
    expect($result['load_avg_1m'])->toBeNull();
    expect($result['load_avg_5m'])->toBeNull();
    expect($result['load_avg_15m'])->toBeNull();
    expect($result['ram_total_mb'])->toBeNull();
    expect($result['ram_used_mb'])->toBeNull();
    expect($result['ram_percent'])->toBeNull();
    expect($result['uptime_seconds'])->toBeNull();

    // D-02 fallback chain ends at a default of 1 core
    expect($result['cpu_cores'])->toBe(1);

    // Disk metrics come from PHP builtins (disk_total_space/disk_free_space),
    // which work on every platform — not /proc
    expect($result['disk_total_gb'])->toBeFloat();
    expect($result['disk_used_gb'])->toBeFloat();
    expect($result['disk_percent'])->toBeFloat();
});

it('returns cpu data from /proc/cpuinfo and sys_getloadavg', function () {
    if (! function_exists('sys_getloadavg')) {
        $this->markTestSkipped('sys_getloadavg() not available on this platform');
    }

    $cpuInfo = [
        'processor' => '0',
        'vendor_id' => 'GenuineIntel',
        'processor' => '1',
        'processor' => '2',
        'processor' => '3',
    ];

    $collector = makeServerCollector(['/proc/cpuinfo' => $cpuInfo]);

    $load = sys_getloadavg();
    $result = $collector->collect([]);

    expect($result['cpu_cores'])->toBe(4);
    expect($result['load_avg_1m'])->toBe($load[0]);
    expect($result['load_avg_5m'])->toBe($load[1]);
    expect($result['load_avg_15m'])->toBe($load[2]);
    expect($result['cpu_percent'])->toBeFloat();
});

it('returns ram data from /proc/meminfo using exact-kb math', function () {
    $collector = makeServerCollector(['/proc/meminfo' => ['MemTotal' => '8000000', 'MemAvailable' => '4000000']]);

    $result = $collector->collect([]);

    expect($result['ram_total_mb'])->toBe(7812.5);
    expect($result['ram_used_mb'])->toBe(3906.3);
    expect($result['ram_percent'])->toBe(50.0);
});

it('parses ram from free -m as fallback source', function () {
    $collector = makeServerCollector([], [], ['free -m' => "               total        used        free      shared  buff/cache   available\nMem:            7982        4000        1500         100        2482        3000\nSwap:           2048           0        2048\n"]);

    $result = $collector->collect([]);

    expect($result['ram_total_mb'])->toBe(7982.0);
    expect($result['ram_used_mb'])->toBe(4000.0);
    expect($result['ram_percent'])->toBe(50.1);
});

it('returns disk data from php builtins', function () {
    $result = makeServerCollector()->collect([]);

    expect($result['disk_total_gb'])->toBeFloat();
    expect($result['disk_used_gb'])->toBeFloat();
    expect($result['disk_percent'])->toBeFloat();

    // Sanity: total should be > used
    expect($result['disk_total_gb'])->toBeGreaterThan($result['disk_used_gb']);
});

it('returns uptime from /proc/uptime as integer seconds', function () {
    $collector = makeServerCollector([], ['/proc/uptime' => '12345.67 98765.43']);

    $result = $collector->collect([]);

    expect($result['uptime_seconds'])->toBe(12345);
    expect($result['uptime_seconds'])->toBeInt();
});

it('handles partial metric failure independently', function () {
    $collector = makeServerCollector(['/proc/cpuinfo' => ['processor' => '0']]);

    $result = $collector->collect([]);

    expect($result['cpu_cores'])->toBe(1);

    // RAM metrics are null (failed independently)
    expect($result['ram_total_mb'])->toBeNull();
    expect($result['ram_used_mb'])->toBeNull();
    expect($result['ram_percent'])->toBeNull();
});

it('handles base collector exception wrapping', function () {
    $collector = new class extends ServerCollector
    {
        protected function doCollect(array $config): array
        {
            throw new RuntimeException('Simulated failure');
        }
    };

    $result = $collector->collect([]);

    expect($result)->toBe([]);
});

it('regression: ram_used_mb is computed from exact kb, not rounded mb', function () {
    // Previously: round(round(8000000/1024) - round(4000000/1024), 1) = 3906.2
    // Correct:    round((8000000 - 4000000) / 1024, 1)               = 3906.3
    $collector = makeServerCollector(['/proc/meminfo' => ['MemTotal' => '8000000', 'MemAvailable' => '4000000']]);

    $result = $collector->collect([]);

    expect($result['ram_used_mb'])->toBe(3906.3);
});

it('regression: disk_used_gb is computed from exact bytes, not rounded gb', function () {
    // 1,000,000,000 bytes = 0.93 GB → rounds to 0.9
    // 500,000,000 bytes   = 0.47 GB → rounds to 0.5
    // Previously: round(round(total) - round(free), 1) = round(0.9 - 0.5, 1) = 0.4
    // Correct:    round((1e9 - 5e8) / 2^30, 1)         = 0.5
    $collector = new class extends ServerCollector
    {
        public function computeDiskForTest(): array
        {
            return $this->computeDiskFromBytes(1000000000, 500000000);
        }
    };

    $result = $collector->computeDiskForTest();

    expect($result['disk_used_gb'])->toBe(0.5);
    expect($result['disk_percent'])->toBe(50.0);
});
