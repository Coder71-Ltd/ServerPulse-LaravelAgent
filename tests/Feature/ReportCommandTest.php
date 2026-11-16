<?php

use Illuminate\Support\Facades\Http;
use ServerPulse\Agent\Collectors\LogsCollector;
use ServerPulse\Agent\ServerPulseServiceProvider;
use ServerPulse\Agent\Services\ConfigService;
use ServerPulse\Agent\Support\LogChannelResolver;
use ServerPulse\Agent\Support\LogCursorStore;

/**
 * Build an isolated log file, cursor store and collector.
 *
 * @return array{0: LogsCollector, 1: string, 2: string, 3: string}
 */
function spReportFixture(string $logContent): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sp_report_'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    $file = $dir.'/app.log';
    file_put_contents($file, $logContent);
    $statePath = $dir.'/state.json';

    $collector = new LogsCollector(
        new LogChannelResolver(['app' => ['driver' => 'single', 'path' => $file]]),
        new LogCursorStore($statePath),
    );

    return [$collector, $statePath, $dir, $file];
}

/**
 * Write a fresh agent config cache so the command does not hit the API.
 *
 * @param  array<string, mixed>  $overrides
 */
function spWriteReportConfig(string $dir, array $overrides = []): string
{
    $path = $dir.'/config.json';

    file_put_contents($path, json_encode(array_merge([
        'enabled' => true,
        'log_channels' => ['app'],
        'log_paths' => [],
        'log_first_run_bytes' => 32768,
        'git_paths' => [],
        'collect' => ['logs' => true],
    ], $overrides)));

    return $path;
}

beforeEach(function () {
    $this->app->register(ServerPulseServiceProvider::class);
    Http::preventStrayRequests();
    config([
        'services.serverpulse.api_base' => 'https://test.example.com',
        'services.serverpulse.api_key' => 'test_key_123',
    ]);
});

afterEach(function () {
    $lockFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'serverpulse.lock';
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
});

it('runs report command and sends payload to API', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_e2e_cache';
    if (file_exists($cachePath)) {
        unlink($cachePath);
    }

    $this->app->instance(ConfigService::class, new ConfigService($cachePath));

    Http::fake([
        'test.example.com/*' => Http::response(['status' => 'accepted'], 202),
    ]);

    $this->artisan('serverpulse:report')->assertSuccessful();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && isset($body['timestamp'], $body['heartbeat'], $body['agent_ver'])
            && $body['agent_ver'] === '1.1'
            && $body['heartbeat'] === true
            && isset($body['domain'])
            && isset($body['php'])
            && isset($body['laravel']);
    });

    if (file_exists($cachePath)) {
        unlink($cachePath);
    }
});

it('exits when agent is disabled in config', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_disabled_cache';
    file_put_contents($cachePath, json_encode(['enabled' => false]));

    $this->app->instance(ConfigService::class, new ConfigService($cachePath));

    Http::fake();

    $this->artisan('serverpulse:report')->assertSuccessful();

    Http::assertNothingSent();

    unlink($cachePath);
});

it('prevents concurrent execution via pid lock', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'test_lock_cache';
    file_put_contents($cachePath, json_encode(['enabled' => true]));

    $this->app->instance(ConfigService::class, new ConfigService($cachePath));

    Http::fake([
        'test.example.com/*' => Http::response(['status' => 'accepted'], 202),
    ]);

    $lockFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'serverpulse.lock';
    file_put_contents($lockFile, (string) getmypid());

    $this->artisan('serverpulse:report')->assertSuccessful();

    Http::assertNothingSent();

    unlink($lockFile);
    unlink($cachePath);
});

it('commits log cursors and sends channel metadata after a successful report', function () {
    [$collector, $statePath, $dir] = spReportFixture(
        '['.date('Y-m-d H:i:s')."] local.INFO: hello\n"
    );

    try {
        $this->app->instance(LogsCollector::class, $collector);
        $this->app->instance(ConfigService::class, new ConfigService(spWriteReportConfig($dir)));

        Http::fake([
            'test.example.com/*' => Http::response(['status' => 'accepted'], 202),
        ]);

        $this->artisan('serverpulse:report')->assertSuccessful();

        expect(file_exists($statePath))->toBeTrue();

        $state = json_decode(file_get_contents($statePath), true);
        expect($state['channel:app']['offset'])->toBeGreaterThan(0);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $logs = $body['logs'] ?? [];
            $channels = $body['log_channels'] ?? [];

            return count($logs) === 1
                && $logs[0]['channel'] === 'app'
                && is_string($logs[0]['cursor'])
                && $logs[0]['count'] === 1
                && count($channels) === 1
                && $channels[0]['name'] === 'app';
        });
    } finally {
        @unlink($statePath);
        @unlink($dir.'/config.json');
        @unlink($dir.'/app.log');
        @rmdir($dir);
    }
});

it('sends an empty logs array when there are no new lines', function () {
    [$collector, $statePath, $dir] = spReportFixture(
        '['.date('Y-m-d H:i:s')."] local.INFO: once\n"
    );

    try {
        $this->app->instance(LogsCollector::class, $collector);
        $this->app->instance(ConfigService::class, new ConfigService(spWriteReportConfig($dir)));

        $payloads = [];

        Http::fake(function ($request) use (&$payloads) {
            $payloads[] = $request->data();

            return Http::response(['status' => 'accepted'], 202);
        });

        $this->artisan('serverpulse:report')->assertSuccessful();
        $this->artisan('serverpulse:report')->assertSuccessful();

        expect($payloads)->toHaveCount(2);
        expect($payloads[0]['logs'])->toHaveCount(1);
        expect($payloads[1]['logs'])->toBe([]);
    } finally {
        @unlink($statePath);
        @unlink($dir.'/config.json');
        @unlink($dir.'/app.log');
        @rmdir($dir);
    }
});

it('re-sends a batch after a failed report and stops after a successful retry', function () {
    [$collector, $statePath, $dir] = spReportFixture(
        '['.date('Y-m-d H:i:s')."] local.INFO: once\n"
    );

    try {
        $this->app->instance(LogsCollector::class, $collector);
        $this->app->instance(ConfigService::class, new ConfigService(spWriteReportConfig($dir)));

        $payloads = [];
        $attempt = 0;

        Http::fake(function ($request) use (&$payloads, &$attempt) {
            $payloads[] = $request->data();
            $attempt++;

            return $attempt === 1
                ? Http::response(['error' => 'down'], 500)
                : Http::response(['status' => 'accepted'], 202);
        });

        $this->artisan('serverpulse:report')->assertSuccessful();
        $this->artisan('serverpulse:report')->assertSuccessful();
        $this->artisan('serverpulse:report')->assertSuccessful();

        expect($payloads)->toHaveCount(3);
        expect($payloads[0]['logs'])->toHaveCount(1);
        expect($payloads[1]['logs'])->toHaveCount(1);
        expect($payloads[2]['logs'])->toBe([]);
    } finally {
        @unlink($statePath);
        @unlink($dir.'/config.json');
        @unlink($dir.'/app.log');
        @rmdir($dir);
    }
});

it('does not commit log cursors when the report is rejected', function () {
    [$collector, $statePath, $dir] = spReportFixture(
        '['.date('Y-m-d H:i:s')."] local.ERROR: boom\n"
    );

    try {
        $this->app->instance(LogsCollector::class, $collector);
        $this->app->instance(ConfigService::class, new ConfigService(spWriteReportConfig($dir)));

        Http::fake([
            'test.example.com/*' => Http::response(['error' => 'server down'], 500),
        ]);

        $this->artisan('serverpulse:report')->assertSuccessful();

        expect(file_exists($statePath))->toBeFalse();
    } finally {
        @unlink($statePath);
        @unlink($dir.'/config.json');
        @unlink($dir.'/app.log');
        @rmdir($dir);
    }
});
