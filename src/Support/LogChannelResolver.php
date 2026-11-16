<?php

namespace ServerPulse\Agent\Support;

/**
 * Resolves Laravel log channel names to the files they actually write.
 *
 * The agent runs inside the host application, so it can read
 * `config('logging.channels')` directly instead of relying on an operator
 * typing absolute paths. Only file-backed drivers are resolved; network
 * and process handlers (slack, syslog, errorlog, stderr, custom) are
 * reported as not file based.
 */
class LogChannelResolver
{
    /**
     * @param  array<string, mixed>|null  $channels  Injected channel config (tests); null reads the app config.
     */
    public function __construct(
        private ?array $channels = null,
    ) {}

    public function has(string $channel): bool
    {
        return array_key_exists($channel, $this->channels());
    }

    /**
     * Resolve one configured channel into file targets.
     *
     * Stack channels expand to their file-backed members. A channel that
     * cannot be written to a file returns a single target with an `error`.
     *
     * @return array<int, array{channel: string, driver: string, path: ?string, file_based: bool, exists: bool, error: ?string}>
     */
    public function resolve(string $channel): array
    {
        return $this->resolveChannel($channel, []);
    }

    /**
     * Describe every configured channel so the dashboard can offer a picker.
     *
     * @return array<int, array{name: string, driver: string, path: ?string, file_based: bool, exists: bool, readable: bool, error: ?string}>
     */
    public function discover(): array
    {
        $channels = $this->channels();
        ksort($channels);

        $discovered = [];

        foreach (array_keys($channels) as $name) {
            $targets = $this->resolve((string) $name);
            $path = null;
            $fileBased = false;
            $error = null;

            foreach ($targets as $target) {
                if ($target['file_based']) {
                    $fileBased = true;
                    $path ??= $target['path'];
                }

                if ($target['error'] !== null) {
                    $error ??= $target['error'];
                }
            }

            $discovered[] = [
                'name' => (string) $name,
                'driver' => (string) ($channels[$name]['driver'] ?? 'unknown'),
                'path' => $path,
                'file_based' => $fileBased,
                'exists' => $path !== null && is_file($path),
                'readable' => $path !== null && is_readable($path),
                'error' => $fileBased ? null : $error,
            ];
        }

        return $discovered;
    }

    /**
     * @param  array<string, bool>  $seen
     * @return array<int, array{channel: string, driver: string, path: ?string, file_based: bool, exists: bool, error: ?string}>
     */
    private function resolveChannel(string $channel, array $seen): array
    {
        $channels = $this->channels();

        if (! array_key_exists($channel, $channels)) {
            return [$this->target($channel, 'unknown', null, false, 'unknown_channel')];
        }

        if (isset($seen[$channel])) {
            return [];
        }

        $seen[$channel] = true;

        $config = $channels[$channel];

        if (! is_array($config)) {
            return [$this->target($channel, 'unknown', null, false, 'invalid_channel')];
        }

        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'unknown';

        return match ($driver) {
            'single' => $this->resolveSingle($channel, $config, $driver),
            'daily' => $this->resolveDaily($channel, $config, $driver),
            'stack' => $this->resolveStack($channel, $config, $driver, $seen),
            'monolog' => $this->resolveMonolog($channel, $config, $driver),
            default => [$this->target($channel, $driver, null, false, 'not_file_based')],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{channel: string, driver: string, path: ?string, file_based: bool, exists: bool, error: ?string}>
     */
    private function resolveSingle(string $channel, array $config, string $driver): array
    {
        $path = $this->configPath($config);

        if ($path === null) {
            return [$this->target($channel, $driver, null, false, 'missing_path')];
        }

        return [$this->target($channel, $driver, $this->normalizePath($path), true)];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{channel: string, driver: string, path: ?string, file_based: bool, exists: bool, error: ?string}>
     */
    private function resolveDaily(string $channel, array $config, string $driver): array
    {
        $path = $this->configPath($config);

        if ($path === null) {
            return [$this->target($channel, $driver, null, false, 'missing_path')];
        }

        $path = $this->normalizePath($path);
        $latest = $this->latestDailyFile($path);

        return [$this->target($channel, $driver, $latest ?? $this->expectedDailyFile($path), true)];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, bool>  $seen
     * @return array<int, array{channel: string, driver: string, path: ?string, file_based: bool, exists: bool, error: ?string}>
     */
    private function resolveStack(string $channel, array $config, string $driver, array $seen): array
    {
        $members = $config['channels'] ?? null;

        if (! is_array($members)) {
            return [$this->target($channel, $driver, null, false, 'invalid_stack')];
        }

        $targets = [];

        foreach ($members as $member) {
            if (! is_string($member) || $member === '') {
                continue;
            }

            foreach ($this->resolveChannel($member, $seen) as $target) {
                if ($target['file_based']) {
                    $targets[$target['channel'].'|'.($target['path'] ?? '')] = $target;
                }
            }
        }

        if ($targets === []) {
            return [$this->target($channel, $driver, null, false, 'not_file_based')];
        }

        return array_values($targets);
    }

    /**
     * Resolve a `monolog` channel that happens to use a StreamHandler.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array{channel: string, driver: string, path: ?string, file_based: bool, exists: bool, error: ?string}>
     */
    private function resolveMonolog(string $channel, array $config, string $driver): array
    {
        $handler = $config['handler'] ?? null;
        $stream = $config['with']['stream'] ?? $config['handler_with']['stream'] ?? null;

        if (! is_string($handler) || ! str_ends_with($handler, 'StreamHandler') || ! is_string($stream)) {
            return [$this->target($channel, $driver, null, false, 'not_file_based')];
        }

        if (str_starts_with($stream, 'php://')) {
            return [$this->target($channel, $driver, null, false, 'not_file_based')];
        }

        return [$this->target($channel, $driver, $this->normalizePath($stream), true)];
    }

    /**
     * @return array{channel: string, driver: string, path: ?string, file_based: bool, exists: bool, error: ?string}
     */
    private function target(string $channel, string $driver, ?string $path, bool $fileBased, ?string $error = null): array
    {
        return [
            'channel' => $channel,
            'driver' => $driver,
            'path' => $path,
            'file_based' => $fileBased,
            'exists' => $path !== null && is_file($path),
            'error' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function configPath(array $config): ?string
    {
        $path = $config['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Find the newest `{name}-{date}.log` file for a daily channel.
     */
    private function latestDailyFile(string $path): ?string
    {
        $dir = dirname($path);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $pattern = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$base.'-*'
            .($extension !== '' ? '.'.$extension : '');

        $files = glob($pattern);

        if (! is_array($files) || $files === []) {
            return null;
        }

        $files = array_values(array_filter($files, static fn (string $file): bool => is_file($file)));

        if ($files === []) {
            return null;
        }

        usort($files, static function (string $a, string $b): int {
            $mtimeA = @filemtime($a) ?: 0;
            $mtimeB = @filemtime($b) ?: 0;

            return $mtimeA === $mtimeB ? strcmp($b, $a) : $mtimeB <=> $mtimeA;
        });

        return $this->normalizePath($files[0]);
    }

    /**
     * The filename a daily channel would use today when nothing exists yet.
     */
    private function expectedDailyFile(string $path): string
    {
        $dir = dirname($path);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $this->normalizePath(
            rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$base.'-'.date('Y-m-d')
            .($extension !== '' ? '.'.$extension : '')
        );
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function channels(): array
    {
        if ($this->channels !== null) {
            return $this->channels;
        }

        if (! function_exists('config')) {
            return [];
        }

        $channels = config('logging.channels', []);

        return is_array($channels) ? $channels : [];
    }
}
