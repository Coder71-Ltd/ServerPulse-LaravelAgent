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

    /**
     * Maximum number of *.log files to process when a configured
     * log_path points at a directory.
     */
    private const MAX_FILES = 10;

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
     * LOG-06: Directory paths are expanded to their *.log files (newest first,
     *         capped at MAX_FILES) so directory log_paths yield real entries.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array{path: string, count: int, entries: array<int, array{datetime: string, message: string, level: string}>}>
     */
    protected function doCollect(array $config): array
    {
        $logPaths = $this->normalizeLogPaths($config['log_paths'] ?? []);

        if ($logPaths === []) {
            $logPaths = $this->resolveDefaultLogPaths();
        }

        $results = [];

        foreach ($logPaths as $path) {
            if (is_dir($path)) {
                $results = array_merge($results, $this->collectFromDirectory($path));

                continue;
            }

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
     * Expand a directory path into its *.log files (newest first) and
     * process each one. Returns a single zero-count result when the
     * directory contains no log files.
     *
     * @return array<int, array{path: string, count: int, entries: array<int, array{datetime: string, message: string, level: string}>}>
     */
    private function collectFromDirectory(string $dir): array
    {
        $pattern = rtrim(str_replace('\\', '/', $dir), '/').'/*.log';
        $files = glob($pattern);

        if (! is_array($files) || $files === []) {
            return [
                [
                    'path' => $dir,
                    'count' => 0,
                    'entries' => [],
                ],
            ];
        }

        rsort($files);
        $files = array_slice($files, 0, self::MAX_FILES);

        $results = [];

        foreach ($files as $file) {
            if (! is_readable($file)) {
                $results[] = [
                    'path' => $file,
                    'count' => 0,
                    'entries' => [],
                ];

                continue;
            }

            $results[] = $this->processLogFile($file);
        }

        return $results;
    }

    /**
     * Find the latest Laravel log file when no paths are configured.
     *
     * Handles both single-file and daily-rotation (laravel-YYYY-MM-DD.log).
     *
     * @return string[]
     */
    private function resolveDefaultLogPaths(): array
    {
        if (! function_exists('storage_path')) {
            return [sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel.log'];
        }

        $logDir = storage_path('logs');
        $candidates = [];

        if (is_dir($logDir)) {
            $files = glob($logDir.DIRECTORY_SEPARATOR.'laravel-*.log');

            if (is_array($files) && $files !== []) {
                rsort($files);

                return [reset($files)];
            }
        }

        return [storage_path('logs/laravel.log')];
    }

    /**
     * Normalize log paths from mixed formats to a flat string[].
     *
     * Handles both:
     *   - string[]: ["/var/log/laravel.log"]
     *   - object[]: [{"label": "app", "path": "/var/log/app.log"}]
     *
     * @param  array<int, mixed>  $paths
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
     * Uses tail -n 1000 via shell when available (fast, OOM-safe).
     * Falls back to PHP native fseek + fread when shell is unavailable.
     * Parses Monolog entries matching 5 error levels, capped at MAX_ENTRIES.
     *
     * @param  string  $path  Absolute path to the log file
     * @return array{path: string, count: int, entries: array<int, array{datetime: string, message: string, level: string}>}
     */
    private function processLogFile(string $path): array
    {
        $output = $this->readLastLines($path, 1000);

        if ($output === null) {
            return [
                'path' => $path,
                'count' => 0,
                'entries' => [],
            ];
        }

        return $this->parseLogOutput($path, $output);
    }

    /**
     * Read the last N lines from a file.
     *
     * Prefers shell tail for speed and OOM safety on huge files.
     * Falls back to PHP fseek/fread when shell_exec is unavailable
     * (Windows, restricted hosting).
     *
     * @return string|null Contents on success, null on failure
     */
    private function readLastLines(string $path, int $maxLines): ?string
    {
        $output = $this->safeExec(
            'tail -n '.$maxLines.' '.escapeshellarg($path).' 2>/dev/null'
        );

        if ($output !== null) {
            return $output;
        }

        return $this->tailViaPhp($path, $maxLines);
    }

    /**
     * Read last N lines via native PHP — no shell dependency.
     *
     * Uses fseek to read backwards in chunks, buffering only
     * the last ~N lines in memory regardless of file size.
     *
     * @return string|null Content on success, null on failure
     */
    private function tailViaPhp(string $path, int $maxLines): ?string
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        $buffer = '';
        $lineCount = 0;
        $chunkSize = 8192;

        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);

        while ($position > 0 && $lineCount <= $maxLines) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            fseek($handle, $position);
            $chunk = fread($handle, $readSize);
            $buffer = $chunk.$buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($handle);

        if ($buffer === '') {
            return null;
        }

        $lines = explode("\n", $buffer);
        $lines = array_slice($lines, -$maxLines);

        return implode("\n", $lines);
    }

    /**
     * Parse log output and extract structured Monolog entries.
     *
     * Only entries matching the reporting date (today) are kept; older
     * entries are dropped so the payload reflects the current day only.
     *
     * @param  string  $output  Raw log lines
     * @return array{path: string, count: int, entries: array<int, array{datetime: string, message: string, level: string}>}
     */
    private function parseLogOutput(string $path, string $output): array
    {
        $reportingDate = $this->reportingDate();
        $lines = explode("\n", $output);
        $entries = [];

        foreach ($lines as $line) {
            if (preg_match(self::MONOLOG_REGEX, $line, $matches)) {
                if (substr($matches[1], 0, 10) !== $reportingDate) {
                    continue;
                }

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

    /**
     * The date (YYYY-MM-DD) used to filter log entries.
     *
     * Defaults to the current local date, honoring Laravel's timezone
     * when available so the filter matches the report timestamp.
     */
    protected function reportingDate(): string
    {
        if (function_exists('now')) {
            return now()->format('Y-m-d');
        }

        return date('Y-m-d');
    }
}
