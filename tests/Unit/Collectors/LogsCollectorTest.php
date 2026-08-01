<?php

use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Collectors\LogsCollector;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Assert that a log entry has the correct structure.
 */
function assertLogEntryKeys(array $entry): void
{
    expect($entry)->toHaveKeys(['datetime', 'message', 'level']);
}

/**
 * Assert that a log file result has the correct structure.
 */
function assertLogFileResult(array $result): void
{
    expect($result)->toHaveKeys(['path', 'count', 'entries']);
}

/**
 * Create a temporary file with the given content.
 *
 * @return string The temp file path.
 */
function createTempLog(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'sp_test_');
    file_put_contents($path, $content);

    return $path;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('returns all log result keys', function () {
    $collector = new LogsCollector;
    $result = $collector->collect([]);

    expect($result)->toBeArray();
});

it('parses Monolog ERROR line correctly', function () {
    $tempFile = createTempLog('[2026-07-04 10:15:30] production.ERROR: Something broke');

    $collector = new class extends LogsCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (preg_match('/tail -n 1000 (.+) 2>\\/dev\\/null/', $command, $m)) {
                $maybePath = trim($m[1], " \"'");
                if (file_exists($maybePath)) {
                    return file_get_contents($maybePath);
                }
            }

            return null;
        }
    };

    $result = $collector->collect([
        'log_paths' => [$tempFile],
    ]);

    unlink($tempFile);

    expect($result)->toHaveCount(1);
    assertLogFileResult($result[0]);
    expect($result[0]['path'])->toBe($tempFile);
    expect($result[0]['count'])->toBe(1);

    $entry = $result[0]['entries'][0];
    assertLogEntryKeys($entry);
    expect($entry['datetime'])->toBe('2026-07-04 10:15:30');
    expect($entry['level'])->toBe('ERROR');
    expect($entry['message'])->toBe('Something broke');
});

it('captures all 5 Monolog levels', function () {
    $lines = implode("\n", [
        '[2026-07-04 10:15:30] production.ERROR: Error occurred',
        '[2026-07-04 10:15:31] production.WARNING: Warning occurred',
        '[2026-07-04 10:15:32] production.CRITICAL: Critical occurred',
        '[2026-07-04 10:15:33] production.ALERT: Alert occurred',
        '[2026-07-04 10:15:34] production.EMERGENCY: Emergency occurred',
    ]);

    $tempFile = createTempLog($lines);

    $collector = new class extends LogsCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (preg_match('/tail -n 1000 (.+) 2>\\/dev\\/null/', $command, $m)) {
                $maybePath = trim($m[1], " \"'");
                if (file_exists($maybePath)) {
                    return file_get_contents($maybePath);
                }
            }

            return null;
        }
    };

    $result = $collector->collect([
        'log_paths' => [$tempFile],
    ]);

    unlink($tempFile);

    expect($result)->toHaveCount(1);
    expect($result[0]['count'])->toBe(5);

    $levels = array_map(fn ($e) => $e['level'], $result[0]['entries']);
    expect($levels)->toContain('ERROR');
    expect($levels)->toContain('WARNING');
    expect($levels)->toContain('CRITICAL');
    expect($levels)->toContain('ALERT');
    expect($levels)->toContain('EMERGENCY');
});

it('handles missing file gracefully', function () {
    $collector = new class extends LogsCollector
    {
        protected function safeExec(string $command): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([
        'log_paths' => ['/var/log/nonexistent.log'],
    ]);

    expect($result)->toHaveCount(1);
    expect($result[0]['path'])->toBe('/var/log/nonexistent.log');
    expect($result[0]['count'])->toBe(0);
    expect($result[0]['entries'])->toBe([]);
});

it('copes entries at 100', function () {
    $lines = [];
    $levels = ['ERROR', 'WARNING', 'CRITICAL', 'ALERT', 'EMERGENCY'];
    for ($i = 0; $i < 150; $i++) {
        $level = $levels[$i % 5];
        $lines[] = "[2026-07-04 10:00:00] production.{$level}: Log entry {$i}";
    }
    $content = implode("\n", $lines);

    $tempFile = createTempLog($content);

    $collector = new class extends LogsCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (preg_match('/tail -n 1000 (.+) 2>\\/dev\\/null/', $command, $m)) {
                $maybePath = trim($m[1], " \"'");
                if (file_exists($maybePath)) {
                    return file_get_contents($maybePath);
                }
            }

            return null;
        }
    };

    $result = $collector->collect([
        'log_paths' => [$tempFile],
    ]);

    unlink($tempFile);

    expect($result)->toHaveCount(1);
    expect($result[0]['count'])->toBe(150);
    expect($result[0]['entries'])->toHaveCount(100);
});

it('handles non-Monolog lines gracefully', function () {
    $lines = implode("\n", [
        '[2026-07-04 10:15:30] production.INFO: Just info — not counted',
        '[2026-07-04 10:15:31] production.DEBUG: Debug message — not counted',
        '[2026-07-04 10:15:32] Some random non-log message',
        '',
        'Plain text line with no brackets',
    ]);

    $tempFile = createTempLog($lines);

    $collector = new class extends LogsCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (preg_match('/tail -n 1000 (.+) 2>\\/dev\\/null/', $command, $m)) {
                $maybePath = trim($m[1], " \"'");
                if (file_exists($maybePath)) {
                    return file_get_contents($maybePath);
                }
            }

            return null;
        }
    };

    $result = $collector->collect([
        'log_paths' => [$tempFile],
    ]);

    unlink($tempFile);

    expect($result)->toHaveCount(1);
    expect($result[0]['count'])->toBe(0);
    expect($result[0]['entries'])->toBe([]);
});

it('parses multi-line entries from fake tail', function () {
    $lines = implode("\n", [
        '[2026-07-04 10:15:30] production.ERROR: First error',
        'Some non-matching line',
        '[2026-07-04 10:15:31] production.CRITICAL: Critical issue',
        'Another non-matching line',
        '[2026-07-04 10:15:32] production.WARNING: Warning message',
    ]);

    $tempFile = createTempLog($lines);

    $collector = new class extends LogsCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (preg_match('/tail -n 1000 (.+) 2>\\/dev\\/null/', $command, $m)) {
                $maybePath = trim($m[1], " \"'");
                if (file_exists($maybePath)) {
                    return file_get_contents($maybePath);
                }
            }

            return null;
        }
    };

    $result = $collector->collect([
        'log_paths' => [$tempFile],
    ]);

    unlink($tempFile);

    expect($result)->toHaveCount(1);
    expect($result[0]['count'])->toBe(3);

    $messages = array_map(fn ($e) => $e['message'], $result[0]['entries']);
    expect($messages)->toContain('First error');
    expect($messages)->toContain('Critical issue');
    expect($messages)->toContain('Warning message');
});

it('handles base collector exception wrapping', function () {
    $collector = new class extends LogsCollector
    {
        protected function doCollect(array $config): array
        {
            throw new RuntimeException('Simulated failure');
        }
    };

    $result = $collector->collect([]);

    expect($result)->toBe([]);
});

it('handles unreadable file returns zero count', function () {
    $tempFile = createTempLog('[2026-07-04 10:15:30] production.ERROR: Readable file error');

    $collector = new class extends LogsCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (preg_match('/tail -n 1000 (.+) 2>\\/dev\\/null/', $command, $m)) {
                $maybePath = trim($m[1], " \"'");
                if (file_exists($maybePath)) {
                    return file_get_contents($maybePath);
                }
            }

            return null;
        }
    };

    $result = $collector->collect([
        'log_paths' => [$tempFile, '/var/log/unreadable.log'],
    ]);

    unlink($tempFile);

    expect($result)->toHaveCount(2);

    // First file (readable) has count > 0
    expect($result[0]['path'])->toBe($tempFile);
    expect($result[0]['count'])->toBe(1);

    // Second file (unreadable) has count 0
    expect($result[1]['path'])->toBe('/var/log/unreadable.log');
    expect($result[1]['count'])->toBe(0);
    expect($result[1]['entries'])->toBe([]);
});

it('normalizes object-format log paths', function () {
    $tempFile1 = createTempLog('[2026-07-04 10:15:30] production.ERROR: First app error');
    $tempFile2 = createTempLog('[2026-07-04 10:15:31] production.WARNING: Worker warning');

    $collector = new class extends LogsCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (preg_match('/tail -n 1000 (.+) 2>\\/dev\\/null/', $command, $m)) {
                $maybePath = trim($m[1], " \"'");
                if (file_exists($maybePath)) {
                    return file_get_contents($maybePath);
                }
            }

            return null;
        }
    };

    $result = $collector->collect([
        'log_paths' => [
            ['label' => 'app', 'path' => $tempFile1],
            ['label' => 'worker', 'path' => $tempFile2],
        ],
    ]);

    unlink($tempFile1);
    unlink($tempFile2);

    expect($result)->toHaveCount(2);

    // First file from object-format path
    expect($result[0]['path'])->toBe($tempFile1);
    expect($result[0]['count'])->toBe(1);

    // Second file from object-format path
    expect($result[1]['path'])->toBe($tempFile2);
    expect($result[1]['count'])->toBe(1);
});

it('reads log files via PHP fallback when shell is unavailable', function () {
    $content = "[2026-08-01 10:15:30] production.ERROR: Shell offline error\n";
    $content .= "[2026-08-01 10:15:31] production.CRITICAL: PHP fallback test\n";

    $tempFile = createTempLog($content);

    $collector = new class extends LogsCollector
    {
        protected function safeExec(string $command): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([
        'log_paths' => [$tempFile],
    ]);

    unlink($tempFile);

    expect($result)->toHaveCount(1);
    expect($result[0]['path'])->toBe($tempFile);
    expect($result[0]['count'])->toBe(2);
    expect($result[0]['entries'][0]['level'])->toBe('ERROR');
    expect($result[0]['entries'][0]['message'])->toBe('Shell offline error');
    expect($result[0]['entries'][1]['level'])->toBe('CRITICAL');
    expect($result[0]['entries'][1]['message'])->toBe('PHP fallback test');
});

it('returns zero count when both shell and PHP read fail', function () {
    $collector = new class extends LogsCollector
    {
        protected function safeExec(string $command): ?string
        {
            return null;
        }
    };

    $result = $collector->collect([
        'log_paths' => ['/var/log/truly_missing.log'],
    ]);

    expect($result)->toHaveCount(1);
    expect($result[0]['path'])->toBe('/var/log/truly_missing.log');
    expect($result[0]['count'])->toBe(0);
    expect($result[0]['entries'])->toBe([]);
});
