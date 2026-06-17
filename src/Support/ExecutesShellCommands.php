<?php

namespace ServerPulse\Agent\Support;

trait ExecutesShellCommands
{
    /**
     * Execute a shell command safely.
     * Returns trimmed output on success, null on any failure.
     */
    protected function safeExec(string $command): ?string
    {
        if (! $this->isShellAvailable()) {
            return null;
        }

        $output = @shell_exec($command);

        if (! is_string($output)) {
            return null;
        }

        $trimmed = trim($output);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Check if shell_exec is available (not in disable_functions).
     */
    protected function isShellAvailable(): bool
    {
        $disabled = ini_get('disable_functions');

        if (! is_string($disabled) || $disabled === '') {
            return function_exists('shell_exec');
        }

        $disabled = explode(',', $disabled);

        foreach ($disabled as $func) {
            if (trim($func) === 'shell_exec') {
                return false;
            }
        }

        return function_exists('shell_exec');
    }

    /**
     * Helper to safely read a file, returning contents or null on failure.
     */
    protected function safeFileGet(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents !== false ? $contents : null;
    }

    /**
     * Helper to safely parse /proc-like key-value files.
     *
     * @return array<string, string>|null
     */
    protected function safeParseProcFile(string $path): ?array
    {
        $contents = $this->safeFileGet($path);

        if (! is_string($contents)) {
            return null;
        }

        $result = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            $parts = explode(':', $line, 2);
            $result[trim($parts[0])] = trim($parts[1]);
        }

        return $result === [] ? null : $result;
    }

    // Test-only accessors

    /** @internal */
    public function callSafeExec(string $command): ?string
    {
        return $this->safeExec($command);
    }

    /** @internal */
    public function callIsShellAvailable(): bool
    {
        return $this->isShellAvailable();
    }
}
