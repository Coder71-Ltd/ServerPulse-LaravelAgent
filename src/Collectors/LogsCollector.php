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
            $logPaths = $this->resolveDefaultLogPaths();
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
     * @return array{path: string, count: int, entries: array}
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
     * @param  string  $output  Raw log lines
     * @return array{path: string, count: int, entries: array}
     */
    private function parseLogOutput(string $path, string $output): array
    {
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
