<?php

namespace ServerPulse\Agent\Collectors;

use ServerPulse\Agent\Support\ExecutesShellCommands;

class ServerCollector extends BaseCollector
{
    use ExecutesShellCommands;

    public function key(): string
    {
        return 'server';
    }

    /**
     * Collect real server metrics from the local Linux system.
     *
     * On non-Linux platforms, returns all nulls immediately (D-10).
     * Each metric domain has an independent fallback chain (D-09).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function doCollect(array $config): array
    {
        // D-10: Non-Linux early exit — no /proc filesystem available
        if (PHP_OS_FAMILY !== 'Linux') {
            return $this->nullResult();
        }

        return $this->collectMetrics();
    }

    /**
     * The actual metric collection logic, kept free of platform guards so
     * tests (and any platform with the required sources) can exercise the
     * real math instead of a copy.
     *
     * @return array<string, mixed>
     */
    protected function collectMetrics(): array
    {
        try {
            // CPU metrics (SRV-01, SRV-02, SRV-03)
            $load = $this->collectLoadAverages();
            $cores = $this->collectCpuCores();

            $cpuPercent = null;
            if ($load['load_avg_1m'] !== null && $cores > 0) {
                $cpuPercent = round(($load['load_avg_1m'] / $cores) * 100, 1);
            }

            // RAM metrics (SRV-04, SRV-05)
            $ram = $this->collectRamMetrics();

            // Disk metrics (SRV-06, SRV-07)
            $disk = $this->collectDiskMetrics();

            // Uptime metric (SRV-08)
            $uptime = $this->collectUptime();

            return [
                'cpu_percent' => $cpuPercent,
                'load_avg_1m' => $load['load_avg_1m'],
                'load_avg_5m' => $load['load_avg_5m'],
                'load_avg_15m' => $load['load_avg_15m'],
                'cpu_cores' => $cores,
                'ram_total_mb' => $ram['ram_total_mb'],
                'ram_used_mb' => $ram['ram_used_mb'],
                'ram_percent' => $ram['ram_percent'],
                'disk_total_gb' => $disk['disk_total_gb'],
                'disk_used_gb' => $disk['disk_used_gb'],
                'disk_percent' => $disk['disk_percent'],
                'uptime_seconds' => $uptime,
            ];
        } catch (\Throwable $e) {
            return $this->nullResult();
        }
    }

    /**
     * Collect CPU load averages via sys_getloadavg().
     *
     * Raw values are returned (spec §8.1) — no rounding applied.
     *
     * @return array{load_avg_1m: float|null, load_avg_5m: float|null, load_avg_15m: float|null}
     */
    private function collectLoadAverages(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        if ($load === false) {
            return [
                'load_avg_1m' => null,
                'load_avg_5m' => null,
                'load_avg_15m' => null,
            ];
        }

        return [
            'load_avg_1m' => $load[0],
            'load_avg_5m' => $load[1],
            'load_avg_15m' => $load[2],
        ];
    }

    /**
     * Collect CPU core count via fallback chain.
     *
     * Priority (D-02): /proc/cpuinfo → nproc shell → 1
     */
    private function collectCpuCores(): int
    {
        $cpuInfo = $this->safeParseProcFile('/proc/cpuinfo');

        if ($cpuInfo !== null) {
            $count = 0;
            foreach ($cpuInfo as $key => $value) {
                if (str_starts_with($key, 'processor')) {
                    $count++;
                }
            }

            if ($count > 0) {
                return $count;
            }
        }

        $nproc = $this->safeExec('nproc 2>/dev/null');

        if ($nproc !== null && is_numeric($nproc)) {
            return (int) $nproc;
        }

        return 1;
    }

    /**
     * Collect RAM metrics via fallback chain.
     *
     * Priority (D-05): /proc/meminfo → free -m shell → null
     *
     * Used/total are computed from exact KB values before rounding so
     * `ram_used_mb` and `ram_percent` stay consistent with each other.
     *
     * @return array{ram_total_mb: float|null, ram_used_mb: float|null, ram_percent: float|null}
     */
    private function collectRamMetrics(): array
    {
        $memInfo = $this->safeParseProcFile('/proc/meminfo');

        if ($memInfo !== null) {
            $totalKb = isset($memInfo['MemTotal']) ? (int) $memInfo['MemTotal'] : 0;
            $availKb = isset($memInfo['MemAvailable']) ? (int) $memInfo['MemAvailable'] : 0;

            if ($totalKb > 0) {
                $usedKb = $totalKb - $availKb;

                return [
                    'ram_total_mb' => round($totalKb / 1024, 1),
                    'ram_used_mb' => round($usedKb / 1024, 1),
                    'ram_percent' => round(($usedKb / $totalKb) * 100, 1),
                ];
            }
        }

        // Fallback: parse free -m output
        return $this->collectRamFromFree() ?? [
            'ram_total_mb' => null,
            'ram_used_mb' => null,
            'ram_percent' => null,
        ];
    }

    /**
     * Parse `free -m` output as fallback RAM source.
     *
     * @return array{ram_total_mb: float|null, ram_used_mb: float|null, ram_percent: float|null}|null
     */
    private function collectRamFromFree(): ?array
    {
        $output = $this->safeExec('free -m');

        if ($output === null) {
            return null;
        }

        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);

            if (! str_starts_with($line, 'Mem:')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);

            if ($parts === false || count($parts) < 3) {
                continue;
            }

            $totalMb = (float) $parts[1];
            $usedMb = (float) $parts[2];

            if ($totalMb > 0) {
                $percent = round(($usedMb / $totalMb) * 100, 1);

                return [
                    'ram_total_mb' => $totalMb,
                    'ram_used_mb' => $usedMb,
                    'ram_percent' => $percent,
                ];
            }
        }

        return null;
    }

    /**
     * Collect disk metrics for root / only.
     *
     * Used/total are computed from exact byte values before rounding so
     * `disk_used_gb` and `disk_percent` stay consistent with each other.
     *
     * @return array{disk_total_gb: float|null, disk_used_gb: float|null, disk_percent: float|null}
     */
    private function collectDiskMetrics(): array
    {
        $totalBytes = @disk_total_space('/');
        $freeBytes = @disk_free_space('/');

        if ($totalBytes === false || $totalBytes <= 0) {
            return [
                'disk_total_gb' => null,
                'disk_used_gb' => null,
                'disk_percent' => null,
            ];
        }

        $safeFreeBytes = $freeBytes !== false ? $freeBytes : 0;

        return $this->computeDiskFromBytes($totalBytes, $safeFreeBytes);
    }

    /**
     * Derive disk metrics from exact byte values.
     *
     * Extracted so the math is unit-testable without a real filesystem.
     * Used/total are computed from exact bytes before rounding so
     * `disk_used_gb` and `disk_percent` stay consistent with each other.
     *
     * @return array{disk_total_gb: float, disk_used_gb: float, disk_percent: float}
     */
    protected function computeDiskFromBytes(float $totalBytes, float $freeBytes): array
    {
        $usedBytes = $totalBytes - $freeBytes;

        return [
            'disk_total_gb' => round($totalBytes / 1073741824, 1),
            'disk_used_gb' => round($usedBytes / 1073741824, 1),
            'disk_percent' => round(($usedBytes / $totalBytes) * 100, 1),
        ];
    }

    /**
     * Collect system uptime in seconds.
     *
     * Reported as an integer (spec §8.1 / §9 payload example).
     */
    private function collectUptime(): ?int
    {
        $contents = $this->safeFileGet('/proc/uptime');

        if ($contents === null) {
            return null;
        }

        $trimmed = trim($contents);

        if ($trimmed === '') {
            return null;
        }

        $parts = explode(' ', $trimmed);

        return (int) $parts[0];
    }

    /**
     * Return an all-null result (used for non-Linux or error states).
     *
     * @return array<string, null>
     */
    private function nullResult(): array
    {
        return [
            'cpu_percent' => null,
            'load_avg_1m' => null,
            'load_avg_5m' => null,
            'load_avg_15m' => null,
            'cpu_cores' => null,
            'ram_total_mb' => null,
            'ram_used_mb' => null,
            'ram_percent' => null,
            'disk_total_gb' => null,
            'disk_used_gb' => null,
            'disk_percent' => null,
            'uptime_seconds' => null,
        ];
    }
}
