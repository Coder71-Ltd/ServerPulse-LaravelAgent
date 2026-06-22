<?php

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Services\ConfigService;
use ServerPulse\Agent\Services\ReportService;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

it('sends payload and returns success on 202', function () {
    Http::fake([
        'serverpulse.coder71.com/*' => Http::response(['status' => 'accepted'], 202),
    ]);

    $config = new ConfigService(tempCachePath());
    $service = new ReportService;
    $result = $service->send(['test' => 'data'], $config);

    expect($result['success'])->toBeTrue();
    expect($result['status'])->toBe(202);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://serverpulse.coder71.com/v1/agent/report'
            && $request->method() === 'POST'
            && $request->header('X-Agent-Version')[0] === '1.0'
            && ($request->header('X-API-Key')[0] ?? '') === 'sp_dev_agent_key_001'
            && $request['test'] === 'data';
    });
});

it('marks agent disabled on 410 response', function () {
    $cachePath = tempCachePath();
    file_put_contents($cachePath, json_encode(['enabled' => true]));

    Http::fake([
        'serverpulse.coder71.com/*' => Http::response(['error' => 'agent_disabled'], 410),
    ]);

    $config = new ConfigService($cachePath);
    $service = new ReportService;
    $result = $service->send([], $config);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(410);

    $cached = json_decode(file_get_contents($cachePath), true);
    expect($cached['enabled'])->toBeFalse();

    unlink($cachePath);
});

it('returns failure on network error', function () {
    Http::fake([
        'serverpulse.coder71.com/*' => function () {
            throw new Exception('Connection timed out');
        },
    ]);

    $config = new ConfigService(tempCachePath());
    $service = new ReportService;
    $result = $service->send([], $config);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBeNull();
    expect($result['error'])->toContain('timed out');
});
