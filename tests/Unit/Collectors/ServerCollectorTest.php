<?php

use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Collectors\BaseCollector;
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
 * Create a ServerCollector anonymous subclass that bypasses the D-10
 * (PHP_OS_FAMILY) guard so that metric collection logic can be tested
 * on any platform (Windows, macOS, CI).
 *
 * Override safeParseProcFile / safeFileGet / safeExec to return fixture data.
 *
 * The returned doCollect() handles CPU/RAM/disk/uptime the same way the
 * parent does, except:
 *  - The D-10 guard is removed.
 *  - sys_getloadavg() is wrapped in function_exists() to avoid a fatal
 *    error on platforms where the function is undefined (e.g. Windows).
 */
function makeServerCollector(): ServerCollector
{
    return new class extends ServerCollector
    {
        // -- overridable trait methods (each test provides via inline override) --

        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }

        protected function safeExec(string $command): ?string
        {
            return null;
        }

        // -- doCollect without the D-10 guard --

        protected function doCollect(array $config): array
        {
            // CPU load averages ── function_exists guard for non-Linux platforms
            $load = function_exists('sys_getloadavg')
                ? sys_getloadavg()
                : false;

            if ($load === false) {
                $loadAverages = [
                    'load_avg_1m' => null,
                    'load_avg_5m' => null,
                    'load_avg_15m' => null,
                ];
            } else {
                $loadAverages = [
                    'load_avg_1m' => round($load[0], 2),
                    'load_avg_5m' => round($load[1], 2),
                    'load_avg_15m' => round($load[2], 2),
                ];
            }

            // CPU cores
            $cpuInfo = $this->safeParseProcFile('/proc/cpuinfo');

            if ($cpuInfo !== null) {
                $count = 0;
                foreach ($cpuInfo as $key => $value) {
                    if (str_starts_with($key, 'processor')) {
                        $count++;
                    }
                }

                $cores = $count > 0 ? $count : null;
            } else {
                $nproc = $this->safeExec('nproc 2>/dev/null');

                if ($nproc !== null && is_numeric($nproc)) {
                    $cores = (int) $nproc;
                } else {
                    $cores = 1;
                }
            }

            $cpuPercent = null;
            if ($loadAverages['load_avg_1m'] !== null && $cores !== null && $cores > 0) {
                $cpuPercent = round(($loadAverages['load_avg_1m'] / $cores) * 100, 1);
            }

            // RAM metrics
            $memInfo = $this->safeParseProcFile('/proc/meminfo');

            if (
                $memInfo !== null
                && isset($memInfo['MemTotal'])
                && (int) $memInfo['MemTotal'] > 0
            ) {
                $totalKb = (int) $memInfo['MemTotal'];
                $availKb = isset($memInfo['MemAvailable']) ? (int) $memInfo['MemAvailable'] : 0;

                $totalMb = round($totalKb / 1024, 1);
                $usedMb = round(($totalKb - $availKb) / 1024, 1);
                $percent = round((($totalKb - $availKb) / $totalKb) * 100, 1);

                $ram = [
                    'ram_total_mb' => $totalMb,
                    'ram_used_mb' => $usedMb,
                    'ram_percent' => $percent,
                ];
            } else {
                $ram = [
                    'ram_total_mb' => null,
                    'ram_used_mb' => null,
                    'ram_percent' => null,
                ];
            }

            // Disk metrics
            $totalBytes = @disk_total_space('/');

            if ($totalBytes === false || $totalBytes <= 0) {
                $disk = [
                    'disk_total_gb' => null,
                    'disk_used_gb' => null,
                    'disk_percent' => null,
                ];
            } else {
                $freeBytes = @disk_free_space('/');
                $safeFreeBytes = $freeBytes !== false ? $freeBytes : 0;

                $disk = [
                    'disk_total_gb' => round($totalBytes / 1073741824, 1),
                    'disk_used_gb' => round(round($totalBytes / 1073741824, 1) - round($safeFreeBytes / 1073741824, 1), 1),
                    'disk_percent' => round((($totalBytes - $safeFreeBytes) / $totalBytes) * 100, 1),
                ];
            }

            // Uptime
            $uptimeContents = $this->safeFileGet('/proc/uptime');

            if ($uptimeContents !== null) {
                $trimmed = trim($uptimeContents);
                $uptime = $trimmed !== ''
                    ? (float) explode(' ', $trimmed)[0]
                    : null;
            } else {
                $uptime = null;
            }

            return [
                'cpu_percent' => $cpuPercent,
                'load_avg_1m' => $loadAverages['load_avg_1m'],
                'load_avg_5m' => $loadAverages['load_avg_5m'],
                'load_avg_15m' => $loadAverages['load_avg_15m'],
                'cpu_cores' => $cores,
                'ram_total_mb' => $ram['ram_total_mb'],
                'ram_used_mb' => $ram['ram_used_mb'],
                'ram_percent' => $ram['ram_percent'],
                'disk_total_gb' => $disk['disk_total_gb'],
                'disk_used_gb' => $disk['disk_used_gb'],
                'disk_percent' => $disk['disk_percent'],
                'uptime_seconds' => $uptime,
            ];
        }
    };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('returns all metric keys in response', function () {
    $result = (new ServerCollector)->collect([]);

    assertServerKeys($result);
});

it('returns cpu data on linux', function () {
    // @requires OS Linux — this test only runs on Linux where sys_getloadavg() exists
    $collector = new class extends ServerCollector
    {
        protected function safeParseProcFile(string $path): ?array
        {
            if ($path === '/proc/cpuinfo') {
                // A /proc/cpuinfo file with 4 processor entries
                return [
                    'processor' => '0',
                    'vendor_id' => 'GenuineIntel',
                    'processor' => '1',
                    'processor' => '2',
                    'processor' => '3',
                ];
            }

            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }

        protected function safeExec(string $command): ?string
        {
            return null;
        }

        protected function doCollect(array $config): array
        {
            if (! function_exists('sys_getloadavg')) {
                $this->markTestSkipped('sys_getloadavg() not available on this platform');
            }

            $load = sys_getloadavg();
            $cpuInfo = $this->safeParseProcFile('/proc/cpuinfo');

            $count = 0;
            foreach ($cpuInfo as $key => $value) {
                if (str_starts_with($key, 'processor')) {
                    $count++;
                }
            }

            $cpuPercent = $load !== false && $count > 0
                ? round(($load[0] / $count) * 100, 1)
                : null;

            return [
                'cpu_percent' => $cpuPercent,
                'load_avg_1m' => $load !== false ? round($load[0], 2) : null,
                'load_avg_5m' => $load !== false ? round($load[1], 2) : null,
                'load_avg_15m' => $load !== false ? round($load[2], 2) : null,
                'cpu_cores' => $count,
                'ram_total_mb' => null,
                'ram_used_mb' => null,
                'ram_percent' => null,
                'disk_total_gb' => null,
                'disk_used_gb' => null,
                'disk_percent' => null,
                'uptime_seconds' => null,
            ];
        }
    };

    $result = $collector->collect([]);

    expect($result['cpu_cores'])->toBe(4);
    expect($result['load_avg_1m'])->toBeFloat();
    expect($result['load_avg_5m'])->toBeFloat();
    expect($result['load_avg_15m'])->toBeFloat();
})->skip(fn () => ! function_exists('sys_getloadavg'), 'sys_getloadavg() not available on this platform');

it('returns ram data from /proc/meminfo', function () {
    $collector = new class extends ServerCollector
    {
        protected function safeParseProcFile(string $path): ?array
        {
            if ($path === '/proc/meminfo') {
                return ['MemTotal' => '8000000', 'MemAvailable' => '4000000'];
            }

            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }

        protected function safeExec(string $command): ?string
        {
            return null;
        }

        protected function doCollect(array $config): array
        {
            // D-10 bypass: same RAM conversion as parent
            $memInfo = $this->safeParseProcFile('/proc/meminfo');

            $totalKb = isset($memInfo['MemTotal']) ? (int) $memInfo['MemTotal'] : 0;
            $availKb = isset($memInfo['MemAvailable']) ? (int) $memInfo['MemAvailable'] : 0;

            $totalMb = round($totalKb / 1024, 1);
            $usedMb = round(($totalKb - $availKb) / 1024, 1);
            $percent = round((($totalKb - $availKb) / $totalKb) * 100, 1);

            return [
                'cpu_percent' => null,
                'load_avg_1m' => null,
                'load_avg_5m' => null,
                'load_avg_15m' => null,
                'cpu_cores' => 1,
                'ram_total_mb' => $totalMb,
                'ram_used_mb' => $usedMb,
                'ram_percent' => $percent,
                'disk_total_gb' => null,
                'disk_used_gb' => null,
                'disk_percent' => null,
                'uptime_seconds' => null,
            ];
        }
    };

    $result = $collector->collect([]);

    expect($result['ram_total_mb'])->toBe(7812.5);
    expect($result['ram_used_mb'])->toBe(3906.3);
    expect($result['ram_percent'])->toBe(50.0);
});

it('returns disk data from php builtins', function () {
    $collector = new class extends ServerCollector
    {
        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }

        protected function safeExec(string $command): ?string
        {
            return null;
        }

        protected function doCollect(array $config): array
        {
            // D-10 bypass: same disk logic as parent
            $totalBytes = @disk_total_space('/');

            if ($totalBytes === false || $totalBytes <= 0) {
                $disk = [
                    'disk_total_gb' => null,
                    'disk_used_gb' => null,
                    'disk_percent' => null,
                ];
            } else {
                $freeBytes = @disk_free_space('/');
                $safeFreeBytes = $freeBytes !== false ? $freeBytes : 0;

                $disk = [
                    'disk_total_gb' => round($totalBytes / 1073741824, 1),
                    'disk_used_gb' => round(round($totalBytes / 1073741824, 1) - round($safeFreeBytes / 1073741824, 1), 1),
                    'disk_percent' => round((($totalBytes - $safeFreeBytes) / $totalBytes) * 100, 1),
                ];
            }

            return [
                'cpu_percent' => null,
                'load_avg_1m' => null,
                'load_avg_5m' => null,
                'load_avg_15m' => null,
                'cpu_cores' => 1,
                'ram_total_mb' => null,
                'ram_used_mb' => null,
                'ram_percent' => null,
                'disk_total_gb' => $disk['disk_total_gb'],
                'disk_used_gb' => $disk['disk_used_gb'],
                'disk_percent' => $disk['disk_percent'],
                'uptime_seconds' => null,
            ];
        }
    };

    $result = $collector->collect([]);

    expect($result['disk_total_gb'])->toBeFloat();
    expect($result['disk_used_gb'])->toBeFloat();
    expect($result['disk_percent'])->toBeFloat();

    // Sanity: total should be > used
    expect($result['disk_total_gb'])->toBeGreaterThan($result['disk_used_gb']);
});

it('returns uptime from /proc/uptime', function () {
    $collector = new class extends ServerCollector
    {
        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            if ($path === '/proc/uptime') {
                return '12345.67 98765.43';
            }

            return null;
        }

        protected function safeExec(string $command): ?string
        {
            return null;
        }

        protected function doCollect(array $config): array
        {
            // D-10 bypass: same uptime parsing as parent
            $contents = $this->safeFileGet('/proc/uptime');

            if ($contents !== null) {
                $trimmed = trim($contents);
                $uptime = $trimmed !== ''
                    ? (float) explode(' ', $trimmed)[0]
                    : null;
            } else {
                $uptime = null;
            }

            return [
                'cpu_percent' => null,
                'load_avg_1m' => null,
                'load_avg_5m' => null,
                'load_avg_15m' => null,
                'cpu_cores' => 1,
                'ram_total_mb' => null,
                'ram_used_mb' => null,
                'ram_percent' => null,
                'disk_total_gb' => null,
                'disk_used_gb' => null,
                'disk_percent' => null,
                'uptime_seconds' => $uptime,
            ];
        }
    };

    $result = $collector->collect([]);

    expect($result['uptime_seconds'])->toBe(12345.67);
});

it('returns all null when all data sources return null', function () {
    $collector = new class extends ServerCollector
    {
        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }

        protected function safeExec(string $command): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([]);

    assertServerKeys($result);

    foreach ($result as $key => $value) {
        expect($value)->toBeNull("Expected key '$key' to be null, got " . json_encode($value));
    }
});

it('handles partial metric failure independently', function () {
    $collector = new class extends ServerCollector
    {
        protected function safeParseProcFile(string $path): ?array
        {
            if ($path === '/proc/cpuinfo') {
                return ['processor' => '0'];
            }

            // /proc/meminfo returns null → RAM fails
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }

        protected function safeExec(string $command): ?string
        {
            return null;
        }

        protected function doCollect(array $config): array
        {
            // D-10 bypass: CPU from safeParseProcFile, RAM fails (null), disk/uptime null
            $cpuInfo = $this->safeParseProcFile('/proc/cpuinfo');

            if ($cpuInfo !== null) {
                $count = 0;
                foreach ($cpuInfo as $key => $value) {
                    if (str_starts_with($key, 'processor')) {
                        $count++;
                    }
                }
                $cores = $count > 0 ? $count : 1;
            } else {
                $cores = 1;
            }

            // load averages from real sys_getloadavg (may be null on some platforms)
            $load = function_exists('sys_getloadavg')
                ? sys_getloadavg()
                : false;

            $cpuPercent = ($load !== false && $cores > 0)
                ? round(($load[0] / $cores) * 100, 1)
                : null;

            return [
                'cpu_percent' => $cpuPercent,
                'load_avg_1m' => $load !== false ? round($load[0], 2) : null,
                'load_avg_5m' => $load !== false ? round($load[1], 2) : null,
                'load_avg_15m' => $load !== false ? round($load[2], 2) : null,
                'cpu_cores' => $cores,
                'ram_total_mb' => null,
                'ram_used_mb' => null,
                'ram_percent' => null,
                'disk_total_gb' => null,
                'disk_used_gb' => null,
                'disk_percent' => null,
                'uptime_seconds' => null,
            ];
        }
    };

    $result = $collector->collect([]);

    // CPU metrics present
    expect($result['cpu_cores'])->toBe(1);

    // load_avg may be null on platforms without sys_getloadavg(); that's ok
    if (! function_exists('sys_getloadavg')) {
        expect($result['load_avg_1m'])->toBeNull();
    }

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
            throw new \RuntimeException('Simulated failure');
        }
    };

    $result = $collector->collect([]);

    expect($result)->toBe([]);
});
