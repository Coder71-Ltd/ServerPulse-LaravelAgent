<?php

namespace ServerPulse\Agent\Collectors;

use ServerPulse\Agent\Contracts\CommitsAfterReport;
use ServerPulse\Agent\Contracts\ProvidesExtraPayload;
use ServerPulse\Agent\Support\LogChannelResolver;
use ServerPulse\Agent\Support\LogCursorStore;

class LogsCollector extends BaseCollector implements CommitsAfterReport, ProvidesExtraPayload
{
    /**
     * Monolog line header: [datetime] environment.LEVEL: message
     */
    private const MONOLOG_REGEX = '/^\[(\d{4}-\d{2}-\d{2}[ T][0-9:.]+(?:[+-]\d{2}:?\d{2}|Z)?)\]\s+([\w.-]+)\.([A-Z]+):\s?(.*)$/';

    /**
     * Monolog severity ordering.
     */
    private const LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'NOTICE' => 2,
        'WARNING' => 3,
        'ERROR' => 4,
        'CRITICAL' => 5,
        'ALERT' => 6,
        'EMERGENCY' => 7,
    ];

    private const DEFAULT_MIN_LEVEL = 'INFO';

    /**
     * Upper bound on bytes read for one channel in one report.
     */
    private const MAX_BYTES_PER_RUN = 262144;

    /**
     * Upper bound on entries shipped for one channel in one report.
     */
    private const MAX_ENTRIES_PER_RUN = 500;

    /**
     * If a channel is further behind than this, jump to the recent tail
     * instead of spending forever catching up.
     */
    private const MAX_BACKLOG_BYTES = 20971520;

    private const BACKLOG_TAIL_BYTES = 262144;

    private const MAX_ENTRY_BYTES = 8192;

    private const MAX_FILES = 10;

    private ?LogChannelResolver $resolver;

    private ?LogCursorStore $cursorStore;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $pendingState = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $discovery = [];

    /**
     * Optional initial tail for a channel with no cursor yet.
     *
     * Defaults to 0: the first run starts at EOF and sends nothing, so a lost
     * cursor can never cause the same tail to be re-shipped. Set
     * `log_first_run_bytes` in the config to opt into an initial tail.
     */
    private int $firstRunBytes = 0;

    public function __construct(
        ?LogChannelResolver $resolver = null,
        ?LogCursorStore $cursorStore = null,
    ) {
        $this->resolver = $resolver;
        $this->cursorStore = $cursorStore;
    }

    public function key(): string
    {
        return 'logs';
    }

    /**
     * Persist the read offsets only after the report was accepted.
     */
    public function commit(): bool
    {
        if ($this->pendingState === null) {
            return true;
        }

        try {
            if (! $this->store()->save($this->pendingState)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        $this->pendingState = null;

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function extraPayload(): array
    {
        return ['log_channels' => $this->discovery];
    }

    /**
     * Read new lines from every configured channel or log path.
     *
     * LOG-01: `log_channels` selects Laravel channels; paths are resolved from
     *         `config('logging.channels')` by the agent.
     * LOG-02: A channel name is also accepted in the legacy `log_paths` field.
     * LOG-03: Only bytes added since the last committed report are read.
     * LOG-04: A first run starts at EOF; `log_first_run_bytes` opts into a tail.
     * LOG-05: Rotation/truncation resets the offset; partial lines wait.
     * LOG-06: All levels >= INFO are kept, DEBUG is skipped by default.
     * LOG-07: Missing files and unsupported channels never throw.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    protected function doCollect(array $config): array
    {
        $this->pendingState = null;
        $this->discovery = [];
        $this->firstRunBytes = max(0, (int) ($config['log_first_run_bytes'] ?? 0));

        $channels = $this->normalizeChannels($config['log_channels'] ?? []);
        $paths = $this->normalizeLogPaths($config['log_paths'] ?? []);

        if ($channels === [] && $paths === []) {
            $paths = $this->resolveDefaultLogPaths();
        }

        $targets = $this->resolveTargets($channels, $paths);

        try {
            $this->discovery = $this->resolver()->discover();
        } catch (\Throwable $e) {
            $this->discovery = [];
        }

        if ($targets === []) {
            return [];
        }

        $cursors = $this->store()->load();
        $next = $cursors;
        $results = [];
        $seenPaths = [];

        foreach ($targets as $target) {
            if ($target['resolved_path'] !== null) {
                if (isset($seenPaths[$target['resolved_path']])) {
                    continue;
                }
                $seenPaths[$target['resolved_path']] = true;
            }

            [$result, $state] = $this->collectTarget($target, $cursors[$target['key']] ?? null);

            if ($state !== null) {
                $next[$target['key']] = $state;
            }

            if ($this->shouldReport($result)) {
                $results[] = $result;
            }
        }

        $this->pendingState = $next;

        return $results;
    }

    /**
     * @param  string[]  $channels
     * @param  string[]  $paths
     * @return array<int, array{channel: ?string, identifier: string, resolved_path: ?string, key: string, error: ?string}>
     */
    private function resolveTargets(array $channels, array $paths): array
    {
        $targets = [];
        $seenKeys = [];
        $seenPaths = [];

        foreach ($channels as $channelName) {
            $resolved = $this->resolver()->resolve($channelName);

            foreach ($resolved as $target) {
                $key = 'channel:'.$target['channel'];
                $leaf = $target['channel'];

                if (! $target['file_based'] || $target['path'] === null) {
                    if (isset($seenKeys[$key])) {
                        continue;
                    }
                    $seenKeys[$key] = true;
                    $targets[] = [
                        'channel' => $leaf,
                        'identifier' => $leaf,
                        'resolved_path' => null,
                        'key' => $key,
                        'error' => $target['error'] ?? 'not_file_based',
                    ];

                    continue;
                }

                if (isset($seenPaths[$target['path']]) || isset($seenKeys[$key])) {
                    continue;
                }

                $seenPaths[$target['path']] = true;
                $seenKeys[$key] = true;

                $targets[] = [
                    'channel' => $leaf,
                    'identifier' => $leaf,
                    'resolved_path' => $target['path'],
                    'key' => $key,
                    'error' => null,
                ];
            }
        }

        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }

            if (is_dir($path)) {
                $files = $this->directoryFiles($path);

                if ($files === []) {
                    if (! isset($seenKeys['path:'.$path])) {
                        $seenKeys['path:'.$path] = true;
                        $targets[] = [
                            'channel' => null,
                            'identifier' => $path,
                            'resolved_path' => null,
                            'key' => 'path:'.$path,
                            'error' => 'not_readable',
                        ];
                    }

                    continue;
                }

                foreach ($files as $file) {
                    if (isset($seenPaths[$file])) {
                        continue;
                    }
                    $seenPaths[$file] = true;
                    $targets[] = [
                        'channel' => null,
                        'identifier' => $path,
                        'resolved_path' => $file,
                        'key' => 'path:'.$file,
                        'error' => null,
                    ];
                }

                continue;
            }

            if (is_file($path) || is_readable($path)) {
                if (isset($seenPaths[$path])) {
                    continue;
                }
                $seenPaths[$path] = true;
                $targets[] = [
                    'channel' => null,
                    'identifier' => $path,
                    'resolved_path' => $path,
                    'key' => 'path:'.$path,
                    'error' => null,
                ];

                continue;
            }

            if ($this->resolver()->has($path)) {
                foreach ($this->resolver()->resolve($path) as $target) {
                    $key = 'channel:'.$target['channel'];

                    if (! $target['file_based'] || $target['path'] === null) {
                        if (isset($seenKeys[$key])) {
                            continue;
                        }
                        $seenKeys[$key] = true;
                        $targets[] = [
                            'channel' => $target['channel'],
                            'identifier' => $path,
                            'resolved_path' => null,
                            'key' => $key,
                            'error' => $target['error'] ?? 'not_file_based',
                        ];

                        continue;
                    }

                    if (isset($seenPaths[$target['path']]) || isset($seenKeys[$key])) {
                        continue;
                    }

                    $seenPaths[$target['path']] = true;
                    $seenKeys[$key] = true;

                    $targets[] = [
                        'channel' => $target['channel'],
                        'identifier' => $path,
                        'resolved_path' => $target['path'],
                        'key' => $key,
                        'error' => null,
                    ];
                }

                continue;
            }

            if (isset($seenPaths[$path])) {
                continue;
            }
            $seenPaths[$path] = true;
            $targets[] = [
                'channel' => null,
                'identifier' => $path,
                'resolved_path' => null,
                'key' => 'path:'.$path,
                'error' => 'not_readable',
            ];
        }

        return $targets;
    }

    /**
     * @param  array{channel: ?string, identifier: string, resolved_path: ?string, key: string, error: ?string}  $target
     * @param  array<string, mixed>|null  $cursor
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function collectTarget(array $target, ?array $cursor): array
    {
        $result = [
            'path' => $target['identifier'],
            'channel' => $target['channel'],
            'resolved_path' => $target['resolved_path'],
            'cursor' => null,
            'from_offset' => null,
            'to_offset' => null,
            'count' => 0,
            'error_count' => 0,
            'level_counts' => [],
            'levels_skipped' => 0,
            'truncated' => false,
            'error' => $target['error'],
            'entries' => [],
        ];

        if ($target['error'] !== null || $target['resolved_path'] === null) {
            return [$result, null];
        }

        $path = $target['resolved_path'];

        if (! is_readable($path)) {
            $result['error'] = 'not_readable';

            return [$result, null];
        }

        $delta = $this->readDelta($path, $cursor);

        if ($delta === null) {
            $result['error'] = 'read_failed';

            return [$result, null];
        }

        if ($delta['raw'] === '') {
            return [$result, $delta['state']];
        }

        $parsed = $this->parseEntries($delta['raw']);
        $included = [];
        $skipped = 0;
        $firstExcludedOffset = null;

        foreach ($parsed as $entry) {
            if ($this->levelValue($entry['level']) < $this->minLevel()) {
                $skipped++;

                continue;
            }

            if (count($included) >= self::MAX_ENTRIES_PER_RUN) {
                $firstExcludedOffset ??= $entry['offset'];

                continue;
            }

            $included[] = $entry;
        }

        if ($firstExcludedOffset !== null) {
            $delta['raw'] = substr($delta['raw'], 0, $firstExcludedOffset);
            $delta['to'] = $delta['from'] + $firstExcludedOffset;
            $delta['state'] = $this->stateFor(
                $path,
                (int) $delta['state']['inode'],
                $delta['to'],
            );
        }

        $entries = [];
        $levelCounts = [];
        $errorCount = 0;

        foreach ($included as $entry) {
            $level = $entry['level'];

            $entries[] = [
                'datetime' => $entry['datetime'],
                'level' => $level,
                'message' => $entry['message'],
            ];

            $levelCounts[$level] = ($levelCounts[$level] ?? 0) + 1;

            if (in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                $errorCount++;
            }
        }

        $result['cursor'] = sha1(
            $path.'|'.$delta['from'].'|'.$delta['to'].'|'.sha1($delta['raw'])
        );
        $result['from_offset'] = $delta['from'];
        $result['to_offset'] = $delta['to'];
        $result['count'] = count($entries);
        $result['error_count'] = $errorCount;
        $result['level_counts'] = $levelCounts;
        $result['levels_skipped'] = $skipped;
        $result['truncated'] = $delta['dropped'];
        $result['entries'] = $entries;

        return [$result, $delta['state']];
    }

    /**
     * Read the byte range that was added since the stored cursor.
     *
     * @param  array<string, mixed>|null  $cursor
     * @return array{raw: string, from: int, to: int, dropped: bool, state: array<string, mixed>}|null
     */
    private function readDelta(string $path, ?array $cursor): ?array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $stat = fstat($handle);

        if ($stat === false) {
            fclose($handle);

            return null;
        }

        $size = (int) $stat['size'];
        $inode = (int) $stat['ino'];
        $start = 0;
        $dropped = false;

        if ($cursor === null || ! isset($cursor['offset'])) {
            // No cursor yet: start at EOF by default. An optional initial
            // tail is only read when `log_first_run_bytes` is configured.
            if ($this->firstRunBytes > 0) {
                $start = max(0, $size - $this->firstRunBytes);

                if ($start > 0) {
                    $start = $this->alignToLineStart($handle, $start, $size);
                }
            } else {
                $start = $size;
            }
        } elseif (
            ($cursor['path'] ?? null) !== $path
            || (int) ($cursor['inode'] ?? -1) !== $inode
            || $size < (int) $cursor['offset']
            || ! $this->tailHashMatches($handle, $cursor)
        ) {
            $start = 0;
        } else {
            $start = (int) $cursor['offset'];
        }

        if ($start > $size) {
            $start = $size;
        }

        if ($size - $start > self::MAX_BACKLOG_BYTES) {
            $start = max(0, $size - self::BACKLOG_TAIL_BYTES);

            if ($start > 0) {
                $start = $this->alignToLineStart($handle, $start, $size);
            }

            $dropped = true;
        }

        fseek($handle, $start);

        $remaining = min(self::MAX_BYTES_PER_RUN, $size - $start);
        $raw = '';

        while ($remaining > 0) {
            $chunk = fread($handle, min(65536, $remaining));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $raw .= $chunk;
            $remaining -= strlen($chunk);
        }

        $lastNewline = strrpos($raw, "\n");

        if ($lastNewline === false) {
            // The read window contains no complete line. If it was full, skip
            // to the next line boundary so one huge line cannot block the
            // channel forever.
            $dropTo = $start;

            if ($raw !== '' && strlen($raw) >= self::MAX_BYTES_PER_RUN) {
                $dropTo = $this->alignToLineStart($handle, $start + strlen($raw), $size);
                $dropped = true;
            }

            $state = $this->stateFor($path, $inode, $dropTo);
            fclose($handle);

            return [
                'raw' => '',
                'from' => $start,
                'to' => $dropTo,
                'dropped' => $dropped,
                'state' => $state,
            ];
        }

        $raw = substr($raw, 0, $lastNewline + 1);
        $to = $start + strlen($raw);
        $state = $this->stateFor($path, $inode, $to);

        fclose($handle);

        return [
            'raw' => $raw,
            'from' => $start,
            'to' => $to,
            'dropped' => $dropped,
            'state' => $state,
        ];
    }

    /**
     * Verify the bytes just before the cursor still match, detecting a
     * truncate-and-regrow that kept the same inode and a similar size.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $cursor
     */
    private function tailHashMatches($handle, array $cursor): bool
    {
        $offset = (int) ($cursor['offset'] ?? 0);

        if ($offset <= 0) {
            return true;
        }

        $hash = $cursor['tail_hash'] ?? null;

        if (! is_string($hash) || $hash === '') {
            return true;
        }

        return hash_equals($hash, $this->hashBefore($handle, $offset));
    }

    /**
     * Skip to the first complete line at or after $start.
     *
     * @param  resource  $handle
     */
    private function alignToLineStart($handle, int $start, int $size): int
    {
        if ($start <= 0) {
            return 0;
        }

        if ($start >= $size) {
            return $size;
        }

        fseek($handle, $start);

        $scanned = 0;
        $limit = 1048576;

        while (! feof($handle) && $scanned < $limit) {
            $chunk = fread($handle, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $position = strpos($chunk, "\n");

            if ($position !== false) {
                return min($size, $start + $scanned + $position + 1);
            }

            $scanned += strlen($chunk);
        }

        return $size;
    }

    /**
     * @param  resource  $handle
     */
    private function hashBefore($handle, int $offset): string
    {
        if ($offset <= 0) {
            return '';
        }

        $length = min(256, $offset);

        if (fseek($handle, $offset - $length) !== 0) {
            return '';
        }

        $tail = fread($handle, $length);

        return is_string($tail) ? sha1($tail) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function stateFor(string $path, int $inode, int $to): array
    {
        $handle = @fopen($path, 'rb');
        $hash = '';

        if ($handle !== false) {
            $hash = $this->hashBefore($handle, $to);
            fclose($handle);
        }

        return [
            'path' => $path,
            'inode' => $inode,
            'offset' => $to,
            'tail_hash' => $hash,
            'updated_at' => time(),
        ];
    }

    /**
     * Parse raw lines into entries, keeping continuation lines attached.
     *
     * @return array<int, array{datetime: string, level: string, message: string, offset: int}>
     */
    private function parseEntries(string $raw): array
    {
        $entries = [];
        $current = null;
        $offset = 0;
        $length = strlen($raw);

        while ($offset < $length) {
            $newline = strpos($raw, "\n", $offset);

            if ($newline === false) {
                $line = substr($raw, $offset);
                $next = $length;
            } else {
                $line = substr($raw, $offset, $newline - $offset);
                $next = $newline + 1;
            }

            $line = rtrim($line, "\r");

            if (preg_match(self::MONOLOG_REGEX, $line, $matches)) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [
                    'datetime' => $matches[1],
                    'level' => strtoupper($matches[3]),
                    'message' => trim($matches[4]),
                    'offset' => $offset,
                ];
            } elseif ($current !== null && strlen($current['message']) < self::MAX_ENTRY_BYTES) {
                $current['message'] .= "\n".$line;
            }

            $offset = $next;
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        foreach ($entries as &$entry) {
            $entry['message'] = rtrim($entry['message']);

            if (strlen($entry['message']) > self::MAX_ENTRY_BYTES) {
                $original = strlen($entry['message']);
                $entry['message'] = substr($entry['message'], 0, self::MAX_ENTRY_BYTES)
                    ."\n…[truncated ".($original - self::MAX_ENTRY_BYTES).' bytes]';
            }
        }
        unset($entry);

        return $entries;
    }

    private function levelValue(string $level): int
    {
        return self::LEVELS[strtoupper($level)] ?? self::LEVELS[self::DEFAULT_MIN_LEVEL];
    }

    private function minLevel(): int
    {
        return self::LEVELS[self::DEFAULT_MIN_LEVEL];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function shouldReport(array $result): bool
    {
        return $result['error'] !== null
            || $result['truncated']
            || $result['count'] > 0;
    }

    /**
     * @param  array<int, mixed>  $channels
     * @return string[]
     */
    private function normalizeChannels(array $channels): array
    {
        $names = [];

        foreach ($channels as $entry) {
            if (is_string($entry) && $entry !== '') {
                $names[] = $entry;

                continue;
            }

            if (is_array($entry)) {
                $name = $entry['name'] ?? $entry['channel'] ?? $entry['label'] ?? null;

                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Normalize log paths from mixed formats to a flat string[].
     *
     * Handles:
     *   - string[]: ["/var/log/laravel.log"]
     *   - object[]: [{"label": "app", "path": "/var/log/app.log"}]
     *   - map:      {"app": "/var/log/app.log"}
     *
     * @param  array<int|string, mixed>  $paths
     * @return string[]
     */
    private function normalizeLogPaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $entry) {
            if (is_string($entry) && $entry !== '') {
                $normalized[] = $entry;
            } elseif (is_array($entry) && isset($entry['path']) && is_string($entry['path']) && $entry['path'] !== '') {
                $normalized[] = $entry['path'];
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Expand a directory path into its *.log files (newest first).
     *
     * @return string[]
     */
    private function directoryFiles(string $dir): array
    {
        $pattern = rtrim(str_replace('\\', '/', $dir), '/').'/*.log';
        $files = glob($pattern);

        if (! is_array($files) || $files === []) {
            return [];
        }

        rsort($files);

        return array_map(
            static fn (string $file): string => str_replace('\\', '/', $file),
            array_slice($files, 0, self::MAX_FILES)
        );
    }

    /**
     * Find the latest Laravel log file when nothing is configured.
     *
     * @return string[]
     */
    private function resolveDefaultLogPaths(): array
    {
        if (! function_exists('storage_path')) {
            return [sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel.log'];
        }

        $logDir = storage_path('logs');

        if (is_dir($logDir)) {
            $files = glob($logDir.DIRECTORY_SEPARATOR.'laravel-*.log');

            if (is_array($files) && $files !== []) {
                rsort($files);

                return [str_replace('\\', '/', (string) reset($files))];
            }
        }

        return [str_replace('\\', '/', storage_path('logs/laravel.log'))];
    }

    private function resolver(): LogChannelResolver
    {
        return $this->resolver ??= new LogChannelResolver;
    }

    private function store(): LogCursorStore
    {
        return $this->cursorStore ??= new LogCursorStore;
    }
}
