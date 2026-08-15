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

        protected function sysCpuDirectories(): array
        {
            return [];
        }

        protected function doCollect(array $config): array
        {
            // D-10 bypass only — the metric math is the production code path.
            return $this->collectMetrics();
        }
    };
}

/**
 * Windows variant of makeServerCollector: routes doCollect through the real
 * collectWindowsMetrics() path while stubbing the process execution
 * (runWindowsProbe) so no real PowerShell is spawned during tests.
 */
function makeWindowsServerCollector(?string $probeOutput = null): ServerCollector
{
    return new class($probeOutput) extends ServerCollector
    {
        public function __construct(
            private readonly ?string $probeOutput,
        ) {}

        protected function runWindowsProbe(): ?string
        {
            return $this->probeOutput;
        }

        protected function doCollect(array $config): array
        {
            // D-10 bypass only — the metric math is the production code path.
            return $this->collectWindowsMetrics();
        }
    };
}

/**
 * Create a CPU-focused collector that injects deterministic /proc/cpuinfo,
 * /proc/stat and load-average fixtures, plus an isolated CPU snapshot file.
 *
 * Used to exercise collectCpuCores() and collectCpuPercent() without any
 * platform dependency (no real sys_getloadavg / glob / shell).
 */
function makeCpuCollector(
    string $cpuInfo,
    string $statContents,
    array|false|null $loadAverages,
    ?array $snapshot,
): ServerCollector {
    return new class($cpuInfo, $statContents, $loadAverages, $snapshot) extends ServerCollector
    {
        private string $snapshotPath;

        public function __construct(
            private readonly string $cpuInfo,
            private readonly string $statContents,
            private readonly array|false|null $loadAverages,
            private readonly ?array $snapshot,
        ) {
            $this->snapshotPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sp_cpu_snap_'.uniqid();

            if ($snapshot !== null) {
                file_put_contents($this->snapshotPath, json_encode($snapshot));
            }
        }

        protected function safeFileGet(string $path): ?string
        {
            if ($path === '/proc/cpuinfo') {
                return $this->cpuInfo;
            }

            if ($path === '/proc/stat') {
                return $this->statContents;
            }

            return null;
        }

        protected function safeExec(string $command): ?string
        {
            return null;
        }

        protected function loadAverages(): array|false
        {
            if ($this->loadAverages === null) {
                return parent::loadAverages();
            }

            return $this->loadAverages;
        }

        protected function sysCpuDirectories(): array
        {
            return [];
        }

        protected function cpuSnapshotPath(): string
        {
            return $this->snapshotPath;
        }

        protected function doCollect(array $config): array
        {
            return $this->collectMetrics();
        }

        public function snapshotContents(): ?array
        {
            if (! is_file($this->snapshotPath)) {
                return null;
            }

            $decoded = json_decode((string) file_get_contents($this->snapshotPath), true);

            return is_array($decoded) ? $decoded : null;
        }

        public function cleanupSnapshot(): void
        {
            if (is_file($this->snapshotPath)) {
                unlink($this->snapshotPath);
            }
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

it('counts CPU cores from raw /proc/cpuinfo processor lines', function () {
    $cpuInfo = "processor\t: 0\nvendor_id\t: GenuineIntel\nprocessor\t: 1\nprocessor\t: 2\nprocessor\t: 3\n";

    $collector = makeCpuCollector($cpuInfo, '', false, null);
    $result = $collector->collect([]);

    expect($result['cpu_cores'])->toBe(4);
});

it('computes cpu_percent from /proc/stat deltas', function () {
    // Baseline snapshot: total 1000 ticks, 800 idle (i.e. 20% busy at that point).
    $snapshot = ['total' => 1000, 'idle' => 800, 'time' => microtime(true) - 60];

    // Next sample: user 150, nice 30, system 40, idle 800, iowait 10.
    // total = 1030, idle (idle+iowait) = 810.
    // Delta: total +30, idle +10 → busy 20 → 66.7%.
    $stat = "cpu  150 30 40 800 10 0 0 0 0 0\ncpu0 50 10 10 300 5 0 0 0 0 0\n";

    $collector = makeCpuCollector('', $stat, false, $snapshot);

    try {
        $result = $collector->collect([]);

        expect($result['cpu_percent'])->toBe(66.7);

        // The new snapshot is persisted for the next cycle.
        $next = $collector->snapshotContents();
        expect($next)->not->toBeNull();
        expect($next['total'])->toBe(1030);
        expect($next['idle'])->toBe(810);
    } finally {
        $collector->cleanupSnapshot();
    }
});

it('caps cpu_percent at 100 from /proc/stat deltas', function () {
    // Baseline: total 1000 ticks, fully busy (idle 0).
    $snapshot = ['total' => 1000, 'idle' => 0, 'time' => microtime(true) - 60];

    // Next sample: 90 more busy ticks, still zero idle → 100% busy.
    $stat = "cpu  1000 0 90 0 0 0 0 0 0 0\n";

    $collector = makeCpuCollector('', $stat, false, $snapshot);

    try {
        $result = $collector->collect([]);

        expect($result['cpu_percent'])->toBe(100.0);
    } finally {
        $collector->cleanupSnapshot();
    }
});

it('falls back to load estimate capped at 100 on first run', function () {
    // No snapshot yet (first run) → load/cores estimate, never >100.
    $cpuInfo = "processor\t: 0\n";

    $collector = makeCpuCollector($cpuInfo, 'cpu  0 0 0 0 0 0 0 0 0 0', [5.0, 5.0, 5.0], null);

    try {
        $result = $collector->collect([]);

        expect($result['cpu_cores'])->toBe(1);
        expect($result['cpu_percent'])->toBe(100.0);
    } finally {
        $collector->cleanupSnapshot();
    }
});

it('returns null cpu_percent when load source is unavailable on first run', function () {
    $collector = makeCpuCollector('', 'cpu  0 0 0 0 0 0 0 0 0 0', false, null);

    try {
        $result = $collector->collect([]);

        expect($result['cpu_percent'])->toBeNull();
    } finally {
        $collector->cleanupSnapshot();
    }
});

it('returns 4 cores and load averages from injected sources', function () {
    $cpuInfo = "processor\t: 0\nprocessor\t: 1\nprocessor\t: 2\nprocessor\t: 3\n";

    $collector = makeCpuCollector($cpuInfo, '', [1.62, 1.64, 1.75], null);

    try {
        $result = $collector->collect([]);

        expect($result['cpu_cores'])->toBe(4);
        expect($result['load_avg_1m'])->toBe(1.62);
        expect($result['load_avg_5m'])->toBe(1.64);
        expect($result['load_avg_15m'])->toBe(1.75);
        expect($result['cpu_percent'])->toBe(40.5);
    } finally {
        $collector->cleanupSnapshot();
    }
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
    $collector = makeServerCollector([], ['/proc/cpuinfo' => "processor\t: 0\n"]);

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

// ---------------------------------------------------------------------------
// Windows metric collection
// ---------------------------------------------------------------------------

it('windows: parses real metrics from the PowerShell probe', function () {
    $collector = makeWindowsServerCollector('12|16598604|5977956|17848|16');

    $result = $collector->collect([]);

    expect($result['cpu_percent'])->toBe(12.0);
    expect($result['cpu_cores'])->toBe(16);
    expect($result['ram_total_mb'])->toBe(16209.6);
    expect($result['ram_used_mb'])->toBe(10371.7);
    expect($result['ram_percent'])->toBe(64.0);
    expect($result['uptime_seconds'])->toBe(17848);
    expect($result['load_avg_1m'])->toBeNull();
    expect($result['load_avg_5m'])->toBeNull();
    expect($result['load_avg_15m'])->toBeNull();
    expect($result['disk_total_gb'])->toBeFloat();
});

it('windows: returns nulls when probe fails but keeps disk + env cores', function () {
    $collector = makeWindowsServerCollector();

    $result = $collector->collect([]);

    expect($result['cpu_percent'])->toBeNull();
    expect($result['ram_total_mb'])->toBeNull();
    expect($result['ram_used_mb'])->toBeNull();
    expect($result['ram_percent'])->toBeNull();
    expect($result['uptime_seconds'])->toBeNull();

    $envCores = getenv('NUMBER_OF_PROCESSORS');
    $expectedCores = is_string($envCores) && is_numeric($envCores) ? (int) $envCores : 1;
    expect($result['cpu_cores'])->toBe($expectedCores);

    expect($result['disk_total_gb'])->toBeFloat();
});

it('windows: returns nulls for malformed probe output', function () {
    $collector = makeWindowsServerCollector('12|16598604');

    $result = $collector->collect([]);

    expect($result['cpu_percent'])->toBeNull();
    expect($result['ram_total_mb'])->toBeNull();
    expect($result['ram_used_mb'])->toBeNull();
    expect($result['ram_percent'])->toBeNull();
    expect($result['uptime_seconds'])->toBeNull();
});

it('windows: returns nulls for zero total ram', function () {
    $collector = makeWindowsServerCollector('5|0|0|100|8');

    $result = $collector->collect([]);

    expect($result['ram_total_mb'])->toBeNull();
    expect($result['ram_used_mb'])->toBeNull();
    expect($result['ram_percent'])->toBeNull();
});

it('windows: falls back to env cores when probe reports zero cores', function () {
    $collector = makeWindowsServerCollector('5|100000|50000|100|0');

    $result = $collector->collect([]);

    $envCores = getenv('NUMBER_OF_PROCESSORS');
    $expectedCores = is_string($envCores) && is_numeric($envCores) ? (int) $envCores : 1;

    expect($result['cpu_cores'])->toBe($expectedCores);
    expect($result['ram_total_mb'])->toBe(97.7);
    expect($result['ram_used_mb'])->toBe(48.8);
    expect($result['ram_percent'])->toBe(50.0);
    expect($result['uptime_seconds'])->toBe(100);
});

it('windows: runWindowsProbe returns null when shell is unavailable', function () {
    $collector = new class extends ServerCollector
    {
        protected function isShellAvailable(): bool
        {
            return false;
        }

        public function probeForTest(): ?string
        {
            return $this->runWindowsProbe();
        }
    };

    expect($collector->probeForTest())->toBeNull();
});
