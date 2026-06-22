<?php

use Illuminate\Support\Facades\Http;
use ServerPulse\Agent\ServerPulseServiceProvider;
use ServerPulse\Agent\Services\ConfigService;

beforeEach(function () {
    $this->app->register(ServerPulseServiceProvider::class);
    Http::preventStrayRequests();
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
        'serverpulse.coder71.com/*' => Http::response(['status' => 'accepted'], 202),
    ]);

    $this->artisan('serverpulse:report')->assertSuccessful();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && isset($body['timestamp'], $body['heartbeat'], $body['agent_ver'])
            && $body['agent_ver'] === '1.0'
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
        'serverpulse.coder71.com/*' => Http::response(['status' => 'accepted'], 202),
    ]);

    $lockFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'serverpulse.lock';
    file_put_contents($lockFile, (string) getmypid());

    $this->artisan('serverpulse:report')->assertSuccessful();

    Http::assertNothingSent();

    unlink($lockFile);
    unlink($cachePath);
});
