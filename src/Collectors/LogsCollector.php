<?php

namespace ServerPulse\Agent\Collectors;

use ServerPulse\Agent\Support\ExecutesShellCommands;

class LogsCollector extends BaseCollector
{
    use ExecutesShellCommands;

    /**
     * Monolog regex matching ERROR, WARNING, CRITICAL, ALERT, EMERGENCY levels.
     * Captures datetime, channel, level, and message.
     */
    private const MONOLOG_REGEX = '/\[(\d{4}-\d{2}-\d{2}[ \d:]+)\] (\w+)\.(ERROR|WARNING|CRITICAL|ALERT|EMERGENCY): (.*)/';

    /**
     * Maximum number of log entries to return per file.
     */
    private const MAX_ENTRIES = 100;

    public function key(): string
    {
        return 'logs';
    }

    /**
     * Collect error/warning log entries from configured log files.
     *
     * LOG-01: Reads log_paths from API config, falls back to ['/var/log/laravel.log'].
     * LOG-02: Processes each log file via tail -n 1000 / safeExec().
     * LOG-03: Parses Monolog ERROR/WARNING/CRITICAL/ALERT/EMERGENCY lines.
     * LOG-04: Returns structured entries per file with capped results.
     * LOG-05: Missing/unreadable files return zero count — never crash.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array{path: string, count: int, entries: array}>
     */
    protected function doCollect(array $config): array
    {
        $logPaths = $this->normalizeLogPaths($config['log_paths'] ?? []);

        if ($logPaths === []) {
            $defaultPath = function_exists('storage_path')
                ? storage_path('logs/laravel.log')
                : sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel.log';

            $logPaths = [$defaultPath];
        }

        $results = [];
        foreach ($logPaths as $path) {
            if (! is_readable($path)) {
                $results[] = [
                    'path' => $path,
                    'count' => 0,
                    'entries' => [],
                ];

                continue;
            }

            $results[] = $this->processLogFile($path);
        }

        return $results;
    }

    /**
     * Normalize log paths from mixed formats to a flat string[].
     *
     * Handles both:
     *   - string[]: ["/var/log/laravel.log"]
     *   - object[]: [{"label": "app", "path": "/var/log/app.log"}]
     *
     * @return string[]
     */
    private function normalizeLogPaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $entry) {
            if (is_string($entry)) {
                $normalized[] = $entry;
            } elseif (is_array($entry) && isset($entry['path']) && is_string($entry['path'])) {
                $normalized[] = $entry['path'];
            }
        }

        return $normalized;
    }

    /**
     * Process a single log file and return structured results.
     *
     * Uses tail -n 1000 to read the last 1000 lines (OOM-safe per threat model T-04-Dos).
     * Parses Monolog entries matching 5 error levels, capped at MAX_ENTRIES.
     *
     * @param  string  $path  Absolute path to the log file
     * @return array{path: string, count: int, entries: array}
     */
    private function processLogFile(string $path): array
    {
        $output = $this->safeExec(
            'tail -n 1000 '.escapeshellarg($path).' 2>/dev/null'
        );

        if ($output === null) {
            return [
                'path' => $path,
                'count' => 0,
                'entries' => [],
            ];
        }

        $lines = explode("\n", $output);
        $entries = [];

        foreach ($lines as $line) {
            if (preg_match(self::MONOLOG_REGEX, $line, $matches)) {
                $entries[] = [
                    'datetime' => $matches[1],
                    'message' => trim($matches[4]),
                    'level' => $matches[3],
                ];
            }
        }

        $count = count($entries);
        $capped = array_slice($entries, 0, self::MAX_ENTRIES);

        return [
            'path' => $path,
            'count' => $count,
            'entries' => $capped,
        ];
    }
}
