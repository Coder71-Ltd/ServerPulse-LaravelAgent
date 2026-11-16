<?php

namespace ServerPulse\Agent\Support;

/**
 * File-backed store for per-channel log read offsets.
 *
 * The state is written only after a report is accepted by the server, so a
 * failed request is retried with the same byte range instead of silently
 * losing log lines.
 */
class LogCursorStore
{
    public function __construct(
        private ?string $path = null,
    ) {}

    public function path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }

        if (function_exists('storage_path')) {
            return storage_path('app/serverpulse/log-cursors.json');
        }

        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'.serverpulse-log-cursors.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return bool True when the state was written.
     */
    public function save(array $state): bool
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $contents = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($contents === false) {
            return false;
        }

        // Unique temp name so two app instances on the same host cannot
        // clobber each other's temporary file.
        $temporary = $path.'.'.getmypid().'.'.uniqid('', true).'.tmp';

        if (@file_put_contents($temporary, $contents) !== false) {
            if (DIRECTORY_SEPARATOR === '\\' && file_exists($path)) {
                @unlink($path);
            }

            if (! is_dir($path) && @rename($temporary, $path)) {
                return true;
            }

            @unlink($temporary);
        }

        // The directory can be read-only while the file itself is writable
        // (for example a previous run created it as another user). Fall back
        // to writing in place instead of losing the cursor.
        if (is_dir($path)) {
            return false;
        }

        return @file_put_contents($path, $contents, LOCK_EX) !== false;
    }
}
