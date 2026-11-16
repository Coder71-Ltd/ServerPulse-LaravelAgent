<?php

use ServerPulse\Agent\Collectors\LogsCollector;
use ServerPulse\Agent\Support\LogChannelResolver;
use ServerPulse\Agent\Support\LogCursorStore;

/**
 * Create a unique temporary directory for log fixtures.
 */
function spCollectorDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sp_logs_'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    return $dir;
}

/**
 * Build a Monolog-formatted line.
 */
function spMonolog(string $level, string $message, ?string $datetime = null): string
{
    return '['.($datetime ?? date('Y-m-d H:i:s')).'] local.'.$level.': '.$message;
}

/**
 * @param  array<string, mixed>  $channels
 */
function spCollector(array $channels, string $statePath): LogsCollector
{
    return new LogsCollector(
        new LogChannelResolver($channels),
        new LogCursorStore($statePath),
    );
}

it('sends only lines added since the last committed run', function () {
    $dir = spCollectorDir();
    $file = $dir.'/sms.log';
    $collector = spCollector(['sms' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    file_put_contents($file, spMonolog('INFO', 'one')."\n".spMonolog('INFO', 'two')."\n");

    $first = $collector->collect(['log_channels' => ['sms'], 'log_first_run_bytes' => 0]);
    $collector->commit();

    expect($first)->toBe([]);

    file_put_contents($file, spMonolog('INFO', 'three')."\n", FILE_APPEND);

    $second = $collector->collect(['log_channels' => ['sms'], 'log_first_run_bytes' => 0]);
    $collector->commit();

    expect($second)->toHaveCount(1);
    expect($second[0]['count'])->toBe(1);
    expect($second[0]['entries'][0]['message'])->toBe('three');
    expect($second[0]['from_offset'])->toBeGreaterThan(0);

    expect($collector->collect(['log_channels' => ['sms'], 'log_first_run_bytes' => 0]))->toBe([]);
});

it('does not advance the cursor until commit is called', function () {
    $dir = spCollectorDir();
    $file = $dir.'/sms.log';
    $collector = spCollector(['sms' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    file_put_contents($file, spMonolog('INFO', 'one')."\n");

    $config = ['log_channels' => ['sms'], 'log_first_run_bytes' => 65536];

    $first = $collector->collect($config);
    $again = $collector->collect($config);

    expect($first[0]['count'])->toBe(1);
    expect($again[0]['count'])->toBe(1);

    $collector->commit();

    expect($collector->collect($config))->toBe([]);
});

it('reports a failed cursor write and keeps the batch retryable', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';

    $collector = new LogsCollector(
        new LogChannelResolver(['app' => ['driver' => 'single', 'path' => $file]]),
        new LogCursorStore($dir),
    );

    file_put_contents($file, spMonolog('INFO', 'one')."\n");

    $config = ['log_channels' => ['app'], 'log_first_run_bytes' => 65536];

    expect($collector->collect($config))->toHaveCount(1);
    expect($collector->commit())->toBeFalse();

    // The pending state is kept, so the same batch is retried next time.
    $retry = $collector->collect($config);

    expect($retry[0]['count'])->toBe(1);
});

it('starts at the end of the file on the first run', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';
    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    $lines = [];
    for ($i = 0; $i < 2000; $i++) {
        $lines[] = spMonolog('INFO', str_repeat('x', 100).$i);
    }
    file_put_contents($file, implode("\n", $lines)."\n");

    $first = $collector->collect(['log_channels' => ['app'], 'log_first_run_bytes' => 0]);
    $collector->commit();

    expect($first)->toBe([]);

    file_put_contents($file, spMonolog('INFO', 'after baseline')."\n", FILE_APPEND);

    $second = $collector->collect(['log_channels' => ['app'], 'log_first_run_bytes' => 0]);

    expect($second[0]['count'])->toBe(1);
    expect($second[0]['entries'][0]['message'])->toBe('after baseline');
    expect($second[0]['from_offset'])->toBeGreaterThan(0);
});

it('re-reads the initial tail when the cursor state version is old', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';
    $statePath = $dir.'/state.json';

    file_put_contents($file, spMonolog('INFO', 'existing line')."\n");

    // Old state that points at EOF but has no version marker.
    file_put_contents($statePath, json_encode([
        'channel:app' => ['path' => $file, 'offset' => filesize($file)],
    ]));

    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $file]], $statePath);

    $result = $collector->collect(['log_channels' => ['app'], 'log_first_run_bytes' => 65536]);
    $collector->commit();

    expect($result[0]['entries'])->toHaveCount(1);
    expect($result[0]['entries'][0]['message'])->toBe('existing line');

    $state = json_decode(file_get_contents($statePath), true);
    expect($state['__version'])->toBe(2);

    // The next run must not re-read it.
    expect($collector->collect(['log_channels' => ['app']]))->toBe([]);
});

it('caps entries per run and continues on the next run', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';
    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    $lines = [];
    for ($i = 0; $i < 600; $i++) {
        $lines[] = spMonolog('INFO', (string) $i);
    }
    file_put_contents($file, implode("\n", $lines)."\n");

    $first = $collector->collect(['log_channels' => ['app'], 'log_first_run_bytes' => 65536]);
    $collector->commit();

    expect($first[0]['count'])->toBe(500);

    $second = $collector->collect(['log_channels' => ['app']]);

    expect($second[0]['count'])->toBe(100);
    expect($second[0]['entries'][0]['message'])->toBe('500');
});

it('starts from the beginning when a daily file rotates', function () {
    $dir = spCollectorDir();
    $collector = spCollector(['sms' => ['driver' => 'daily', 'path' => $dir.'/sms.log']], $dir.'/state.json');

    $firstFile = $dir.'/sms-2026-11-15.log';
    file_put_contents($firstFile, spMonolog('INFO', 'yesterday')."\n");

    $first = $collector->collect(['log_channels' => ['sms'], 'log_first_run_bytes' => 0]);
    $collector->commit();

    expect($first)->toBe([]);

    $secondFile = $dir.'/sms-2026-11-16.log';
    file_put_contents($secondFile, spMonolog('INFO', 'today')."\n");

    $second = $collector->collect(['log_channels' => ['sms'], 'log_first_run_bytes' => 0]);
    $collector->commit();

    expect($second[0]['entries'][0]['message'])->toBe('today');
    expect($second[0]['from_offset'])->toBe(0);
});

it('re-reads from the start when the file is truncated', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';
    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    file_put_contents($file, spMonolog('INFO', 'old one')."\n".spMonolog('INFO', 'old two')."\n");

    $collector->collect(['log_channels' => ['app']]);
    $collector->commit();

    file_put_contents($file, spMonolog('INFO', 'after truncate')."\n");

    $result = $collector->collect(['log_channels' => ['app']]);

    expect($result[0]['entries'])->toHaveCount(1);
    expect($result[0]['entries'][0]['message'])->toBe('after truncate');
    expect($result[0]['from_offset'])->toBe(0);
});

it('makes progress past a line larger than the read window', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';
    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    file_put_contents($file, spMonolog('INFO', 'first')."\n");
    $collector->collect(['log_channels' => ['app']]);
    $collector->commit();

    // A single unterminated line bigger than MAX_BYTES_PER_RUN.
    file_put_contents($file, str_repeat('x', 300000), FILE_APPEND);
    $collector->collect(['log_channels' => ['app']]);
    $collector->commit();

    file_put_contents($file, "\n".spMonolog('INFO', 'after giant')."\n", FILE_APPEND);

    $result = $collector->collect(['log_channels' => ['app']]);

    expect($result[0]['entries'])->toHaveCount(1);
    expect($result[0]['entries'][0]['message'])->toBe('after giant');
});

it('waits for a complete line before consuming it', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';
    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    // Establish a cursor after a complete line.
    file_put_contents($file, spMonolog('INFO', 'first')."\n");
    $collector->collect(['log_channels' => ['app']]);
    $collector->commit();

    $partial = '['.date('Y-m-d H:i:s').'] local.INFO: hel';
    file_put_contents($file, $partial, FILE_APPEND);

    $first = $collector->collect(['log_channels' => ['app']]);
    $collector->commit();

    expect($first)->toBe([]);

    file_put_contents($file, "lo\n", FILE_APPEND);

    $second = $collector->collect(['log_channels' => ['app']]);

    expect($second[0]['entries'])->toHaveCount(1);
    expect($second[0]['entries'][0]['message'])->toBe('hello');
});

it('keeps stack trace continuation lines with their entry', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';
    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    file_put_contents($file, implode("\n", [
        spMonolog('ERROR', 'First error'),
        '#0 /app/vendor/framework/File.php(10): something()',
        '#1 /app/public/index.php(20): other()',
        spMonolog('WARNING', 'Second warning'),
        '',
    ]));

    $result = $collector->collect(['log_channels' => ['app'], 'log_first_run_bytes' => 65536]);

    expect($result[0]['entries'])->toHaveCount(2);
    expect($result[0]['entries'][0]['message'])->toContain('First error');
    expect($result[0]['entries'][0]['message'])->toContain('#1 /app/public/index.php');
    expect($result[0]['entries'][1]['message'])->toBe('Second warning');
});

it('includes INFO entries and skips DEBUG by default', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';
    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    file_put_contents($file, implode("\n", [
        spMonolog('DEBUG', 'debug noise'),
        spMonolog('INFO', 'useful info'),
        spMonolog('ERROR', 'real error'),
        '',
    ]));

    $result = $collector->collect(['log_channels' => ['app'], 'log_first_run_bytes' => 65536]);
    $levels = array_column($result[0]['entries'], 'level');

    expect($levels)->toBe(['INFO', 'ERROR']);
    expect($result[0]['levels_skipped'])->toBe(1);
});

it('advances past filtered debug lines without resending them', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';
    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    file_put_contents($file, '');

    // First run establishes the cursor at EOF.
    expect($collector->collect(['log_channels' => ['app'], 'log_first_run_bytes' => 0]))->toBe([]);
    $collector->commit();

    file_put_contents($file, spMonolog('DEBUG', 'only debug')."\n", FILE_APPEND);

    expect($collector->collect(['log_channels' => ['app'], 'log_first_run_bytes' => 0]))->toBe([]);
    $collector->commit();

    file_put_contents($file, spMonolog('INFO', 'now info')."\n", FILE_APPEND);

    $result = $collector->collect(['log_channels' => ['app'], 'log_first_run_bytes' => 0]);

    expect($result[0]['entries'])->toHaveCount(1);
    expect($result[0]['entries'][0]['message'])->toBe('now info');
});

it('truncates very large log lines', function () {
    $dir = spCollectorDir();
    $file = $dir.'/app.log';
    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    file_put_contents($file, spMonolog('ERROR', str_repeat('a', 20000))."\n");

    $result = $collector->collect(['log_channels' => ['app'], 'log_first_run_bytes' => 65536]);
    $message = $result[0]['entries'][0]['message'];

    expect(strlen($message))->toBeLessThan(20000);
    expect($message)->toContain('[truncated');
});

it('accepts a channel name in the legacy log_paths field', function () {
    $dir = spCollectorDir();
    $file = $dir.'/sms.log';
    $collector = spCollector(['sms' => ['driver' => 'single', 'path' => $file]], $dir.'/state.json');

    file_put_contents($file, spMonolog('INFO', 'via bridge')."\n");

    $result = $collector->collect([
        'log_paths' => [['label' => 'sms', 'path' => 'sms']],
        'log_first_run_bytes' => 65536,
    ]);

    expect($result)->toHaveCount(1);
    expect($result[0]['channel'])->toBe('sms');
    expect($result[0]['path'])->toBe('sms');
    expect($result[0]['resolved_path'])->toBe($file);
    expect($result[0]['entries'][0]['message'])->toBe('via bridge');
});

it('reports discovered channels as an extra payload', function () {
    $dir = spCollectorDir();
    $collector = spCollector([
        'sms' => ['driver' => 'single', 'path' => $dir.'/sms.log'],
        'slack' => ['driver' => 'slack', 'url' => 'https://example.com'],
    ], $dir.'/state.json');

    $collector->collect(['log_channels' => ['sms']]);

    $extra = $collector->extraPayload();

    expect($extra)->toHaveKey('log_channels');
    expect(array_column($extra['log_channels'], 'name'))->toBe(['slack', 'sms']);
});

it('returns a zero-count result for a missing channel file', function () {
    $dir = spCollectorDir();
    $collector = spCollector(['app' => ['driver' => 'single', 'path' => $dir.'/missing.log']], $dir.'/state.json');

    $result = $collector->collect(['log_channels' => ['app']]);

    expect($result)->toHaveCount(1);
    expect($result[0]['path'])->toBe('app');
    expect($result[0]['count'])->toBe(0);
    expect($result[0]['entries'])->toBe([]);
    expect($result[0]['error'])->toBe('not_readable');
});

it('still expands a directory log path into its log files', function () {
    $dir = spCollectorDir();
    $logs = $dir.'/logs';
    mkdir($logs);

    $fileA = $logs.'/a.log';
    $fileB = $logs.'/b.log';
    file_put_contents($fileA, spMonolog('ERROR', 'from a')."\n");
    file_put_contents($fileB, spMonolog('WARNING', 'from b')."\n");

    $collector = spCollector([], $dir.'/state.json');

    $result = $collector->collect(['log_paths' => [$logs], 'log_first_run_bytes' => 65536]);
    $collector->commit();

    expect($result)->toHaveCount(2);
    expect(array_column($result, 'path'))->toBe([$logs, $logs]);
    expect(array_column($result, 'resolved_path'))->toBe([$fileB, $fileA]);
    expect(array_sum(array_column($result, 'count')))->toBe(2);

    expect($collector->collect(['log_paths' => [$logs]]))->toBe([]);
});

it('handles base collector exception wrapping', function () {
    $collector = new class extends LogsCollector
    {
        protected function doCollect(array $config): array
        {
            throw new RuntimeException('Simulated failure');
        }
    };

    expect($collector->collect([]))->toBe([]);
});
