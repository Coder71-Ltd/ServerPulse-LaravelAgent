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

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('returns all log result keys', function () {
    $collector = new LogsCollector;
    $result = $collector->collect([]);

    expect($result)->toBeArray();
});

it('parses Monolog ERROR line correctly', function () {
    // Create a real temp file so is_readable() passes
    $tempFile = tempnam(sys_get_temp_dir(), 'sp_test_');
    $logContent = '[2026-07-04 10:15:30] production.ERROR: Something broke';
    file_put_contents($tempFile, $logContent);

    $collector = new class($logContent) extends LogsCollector
    {
        private string $logContent;

        public function __construct(string $logContent)
        {
            $this->logContent = $logContent;
        }

        protected function safeExec(string $command): ?string
        {
            return $this->logContent;
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
