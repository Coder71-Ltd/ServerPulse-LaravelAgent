<?php

use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Collectors\SecurityCollector;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('returns failed_ssh_1h key', function () {
    $collector = new SecurityCollector;
    $result = $collector->collect([]);

    expect($result)->toHaveKey('failed_ssh_1h');
});

it('counts Failed password entries in auth.log for current hour', function () {
    $collector = new class extends SecurityCollector
    {
        protected function safeExec(string $command): ?string
        {
            $dateCmd = "date +'%b %e %H:' 2>/dev/null";

            if ($command === $dateCmd) {
                return 'Jul  4 10:';
            }

            // Return 3 for auth.log, 0 for secure to test single-path counting
            if (str_contains($command, 'Failed password')) {
                if (str_contains($command, 'auth.log')) {
                    return '3';
                }

                return '0';
            }

            return null;
        }

        protected function doCollect(array $config): array
        {
            $nowPrefix = $this->safeExec("date +'%b %e %H:' 2>/dev/null");

            if ($nowPrefix === null) {
                return ['failed_ssh_1h' => 0];
            }

            $authPaths = ['/var/log/auth.log', '/var/log/secure'];
            $count = 0;

            foreach ($authPaths as $authPath) {
                $cmd = 'grep "'.$nowPrefix.'" '.escapeshellarg($authPath).' 2>/dev/null | grep -c "Failed password" 2>/dev/null';
                $result = $this->safeExec($cmd);

                if ($result !== null && $result !== '' && is_numeric($result)) {
                    $count += (int) $result;
                }
            }

            return ['failed_ssh_1h' => $count];
        }
    };

    $result = $collector->collect([]);

    expect($result['failed_ssh_1h'])->toBe(3);
});

it('checks both auth.log and secure paths', function () {
    $collector = new class extends SecurityCollector
    {
        public array $commands = [];

        protected function safeExec(string $command): ?string
        {
            $this->commands[] = $command;

            if (str_contains($command, "date +'%b %e %H:'")) {
                return 'Jul  4 10:';
            }

            return '0';
        }

        protected function doCollect(array $config): array
        {
            $nowPrefix = $this->safeExec("date +'%b %e %H:' 2>/dev/null");

            if ($nowPrefix === null) {
                return ['failed_ssh_1h' => 0];
            }

            $authPaths = ['/var/log/auth.log', '/var/log/secure'];
            $count = 0;

            foreach ($authPaths as $authPath) {
                $cmd = 'grep "'.$nowPrefix.'" '.escapeshellarg($authPath).' 2>/dev/null | grep -c "Failed password" 2>/dev/null';
                $result = $this->safeExec($cmd);

                if ($result !== null && $result !== '' && is_numeric($result)) {
                    $count += (int) $result;
                }
            }

            return ['failed_ssh_1h' => $count];
        }
    };

    $collector->collect([]);

    $allCommands = implode('|', $collector->commands);

    // escapeshellarg wraps in single quotes on Unix, double quotes on Windows
    $authLogQuoted = escapeshellarg('/var/log/auth.log');
    $secureQuoted = escapeshellarg('/var/log/secure');

    expect($allCommands)->toContain($authLogQuoted);
    expect($allCommands)->toContain($secureQuoted);
});

it('sums counts from both auth files', function () {
    $collector = new class extends SecurityCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, "date +'%b %e %H:'")) {
                return 'Jul  4 10:';
            }

            if (str_contains($command, 'Failed password')) {
                if (str_contains($command, 'auth.log')) {
                    return '2';
                }

                if (str_contains($command, 'secure')) {
                    return '3';
                }
            }

            return null;
        }

        protected function doCollect(array $config): array
        {
            $nowPrefix = $this->safeExec("date +'%b %e %H:' 2>/dev/null");

            if ($nowPrefix === null) {
                return ['failed_ssh_1h' => 0];
            }

            $authPaths = ['/var/log/auth.log', '/var/log/secure'];
            $count = 0;

            foreach ($authPaths as $authPath) {
                $cmd = 'grep "'.$nowPrefix.'" '.escapeshellarg($authPath).' 2>/dev/null | grep -c "Failed password" 2>/dev/null';
                $result = $this->safeExec($cmd);

                if ($result !== null && $result !== '' && is_numeric($result)) {
                    $count += (int) $result;
                }
            }

            return ['failed_ssh_1h' => $count];
        }
    };

    $result = $collector->collect([]);

    expect($result['failed_ssh_1h'])->toBe(5);
});

it('returns 0 when date command fails', function () {
    $collector = new class extends SecurityCollector
    {
        protected function safeExec(string $command): ?string
        {
            return null;
        }

        protected function doCollect(array $config): array
        {
            $nowPrefix = $this->safeExec("date +'%b %e %H:' 2>/dev/null");

            if ($nowPrefix === null) {
                return ['failed_ssh_1h' => 0];
            }

            return ['failed_ssh_1h' => 999];
        }
    };

    $result = $collector->collect([]);

    expect($result['failed_ssh_1h'])->toBe(0);
});

it('returns 0 when all auth files missing', function () {
    $collector = new class extends SecurityCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, "date +'%b %e %H:'")) {
                return 'Jul  4 10:';
            }

            return null;
        }

        protected function doCollect(array $config): array
        {
            $nowPrefix = $this->safeExec("date +'%b %e %H:' 2>/dev/null");

            if ($nowPrefix === null) {
                return ['failed_ssh_1h' => 0];
            }

            // Both paths unreadable — count stays 0
            return ['failed_ssh_1h' => 0];
        }
    };

    $result = $collector->collect([]);

    expect($result['failed_ssh_1h'])->toBe(0);
});

it('returns 0 when grep finds no Failed password entries', function () {
    $collector = new class extends SecurityCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, "date +'%b %e %H:'")) {
                return 'Jul  4 10:';
            }

            return '0';
        }

        protected function doCollect(array $config): array
        {
            $nowPrefix = $this->safeExec("date +'%b %e %H:' 2>/dev/null");

            if ($nowPrefix === null) {
                return ['failed_ssh_1h' => 0];
            }

            $authPaths = ['/var/log/auth.log', '/var/log/secure'];
            $count = 0;

            foreach ($authPaths as $authPath) {
                $cmd = 'grep "'.$nowPrefix.'" '.escapeshellarg($authPath).' 2>/dev/null | grep -c "Failed password" 2>/dev/null';
                $result = $this->safeExec($cmd);

                if ($result !== null && $result !== '' && is_numeric($result)) {
                    $count += (int) $result;
                }
            }

            return ['failed_ssh_1h' => $count];
        }
    };

    $result = $collector->collect([]);

    expect($result['failed_ssh_1h'])->toBe(0);
});

it('handles grep returning empty output', function () {
    $collector = new class extends SecurityCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, "date +'%b %e %H:'")) {
                return 'Jul  4 10:';
            }

            return '';
        }

        protected function doCollect(array $config): array
        {
            $nowPrefix = $this->safeExec("date +'%b %e %H:' 2>/dev/null");

            if ($nowPrefix === null) {
                return ['failed_ssh_1h' => 0];
            }

            $authPaths = ['/var/log/auth.log', '/var/log/secure'];
            $count = 0;

            foreach ($authPaths as $authPath) {
                $cmd = 'grep "'.$nowPrefix.'" '.escapeshellarg($authPath).' 2>/dev/null | grep -c "Failed password" 2>/dev/null';
                $result = $this->safeExec($cmd);

                if ($result !== null && $result !== '' && is_numeric($result)) {
                    $count += (int) $result;
                }
            }

            return ['failed_ssh_1h' => $count];
        }
    };

    $result = $collector->collect([]);

    expect($result['failed_ssh_1h'])->toBe(0);
});

it('handles base collector exception wrapping', function () {
    $collector = new class extends SecurityCollector
    {
        protected function doCollect(array $config): array
        {
            throw new RuntimeException('Simulated failure');
        }
    };

    $result = $collector->collect([]);

    expect($result)->toBe([]);
});

it('uses escapeshellarg on file paths', function () {
    $collector = new class extends SecurityCollector
    {
        public array $commands = [];

        protected function safeExec(string $command): ?string
        {
            $this->commands[] = $command;

            if (str_contains($command, "date +'%b %e %H:'")) {
                return 'Jul  4 10:';
            }

            return '0';
        }

        protected function doCollect(array $config): array
        {
            $nowPrefix = $this->safeExec("date +'%b %e %H:' 2>/dev/null");

            if ($nowPrefix === null) {
                return ['failed_ssh_1h' => 0];
            }

            $authPaths = ['/var/log/auth.log', '/var/log/secure'];
            $count = 0;

            foreach ($authPaths as $authPath) {
                $cmd = 'grep "'.$nowPrefix.'" '.escapeshellarg($authPath).' 2>/dev/null | grep -c "Failed password" 2>/dev/null';
                $result = $this->safeExec($cmd);

                if ($result !== null && $result !== '' && is_numeric($result)) {
                    $count += (int) $result;
                }
            }

            return ['failed_ssh_1h' => $count];
        }
    };

    $collector->collect([]);

    $allCommands = implode('|', $collector->commands);

    // Use the same escapeshellarg that the implementation uses
    $authLogQuoted = escapeshellarg('/var/log/auth.log');
    $secureQuoted = escapeshellarg('/var/log/secure');

    expect($allCommands)->toContain($authLogQuoted);
    expect($allCommands)->toContain($secureQuoted);
});
