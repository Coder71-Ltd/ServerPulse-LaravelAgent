<?php

use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Collectors\WebServerCollector;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Assert that the result array contains all three web server metric keys.
 */
function assertWebServerKeys(array $result): void
{
    expect($result)->toHaveKeys([
        'server_type',
        'running',
        'active_connections',
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('returns all web server metric keys', function () {
    $collector = new WebServerCollector;
    $result = $collector->collect([]);

    assertWebServerKeys($result);
});

it('reports nginx when pgrep finds nginx process', function () {
    $collector = new class extends WebServerCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'pgrep -x nginx')) {
                return '1234';
            }

            return null;
        }

        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([]);

    expect($result['server_type'])->toBe('nginx');
    expect($result['running'])->toBeTrue();
});

it('reports apache when pgrep finds apache2 process', function () {
    $collector = new class extends WebServerCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'pgrep -x apache2')) {
                return '5678';
            }

            return null;
        }

        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([]);

    expect($result['server_type'])->toBe('apache');
    expect($result['running'])->toBeTrue();
});

it('reports apache when pgrep finds httpd process (RHEL)', function () {
    $collector = new class extends WebServerCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'pgrep -x httpd')) {
                return '9012';
            }

            return null;
        }

        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([]);

    expect($result['server_type'])->toBe('apache');
    expect($result['running'])->toBeTrue();
});

it('reports null server when no web server process found', function () {
    $collector = new class extends WebServerCollector
    {
        protected function safeExec(string $command): ?string
        {
            return null;
        }

        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([]);

    expect($result['server_type'])->toBeNull();
    expect($result['running'])->toBeNull();
});

it('counts active connections from ss state established', function () {
    $fakeSsOutput = 'State       Recv-Q      Send-Q      Local Address:Port        Peer Address:Port
ESTAB       0           0           127.0.0.1:80              10.0.0.1:34567
ESTAB       0           0           127.0.0.1:80              10.0.0.2:34568';

    $collector = new class($fakeSsOutput) extends WebServerCollector
    {
        private string $fakeSsOutput;

        public function __construct(string $fakeSsOutput)
        {
            $this->fakeSsOutput = $fakeSsOutput;
        }

        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'ss -t state established')) {
                return $this->fakeSsOutput;
            }

            return null;
        }

        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([]);

    expect($result['active_connections'])->toBe(2);
});

it('falls back to ss -s summary when state established fails', function () {
    $collector = new class extends WebServerCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'ss -t state established')) {
                return null;
            }

            if (str_contains($command, 'ss -s')) {
                return 'TCP:   45 (estab 12, closed 10, orphaned 0, ...)';
            }

            return null;
        }

        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([]);

    expect($result['active_connections'])->toBe(12);
});

it('returns null active connections when both ss methods fail', function () {
    $collector = new class extends WebServerCollector
    {
        protected function safeExec(string $command): ?string
        {
            return null;
        }

        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([]);

    expect($result['active_connections'])->toBeNull();
});

it('handles empty ss state established output gracefully', function () {
    $fakeSsHeaderOnly = 'State       Recv-Q      Send-Q      Local Address:Port        Peer Address:Port';

    $collector = new class($fakeSsHeaderOnly) extends WebServerCollector
    {
        private string $fakeSsOutput;

        public function __construct(string $fakeSsOutput)
        {
            $this->fakeSsOutput = $fakeSsOutput;
        }

        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'ss -t state established')) {
                return $this->fakeSsOutput;
            }

            return null;
        }

        protected function safeParseProcFile(string $path): ?array
        {
            return null;
        }

        protected function safeFileGet(string $path): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([]);

    expect($result['active_connections'])->toBe(0);
});

it('handles base collector exception wrapping (edge case)', function () {
    $collector = new class extends WebServerCollector
    {
        protected function doCollect(array $config): array
        {
            throw new RuntimeException('Simulated failure');
        }
    };

    $result = $collector->collect([]);

    expect($result)->toBe([]);
});
