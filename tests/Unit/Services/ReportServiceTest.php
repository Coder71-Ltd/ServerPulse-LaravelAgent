<?php

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Services\ConfigService;
use ServerPulse\Agent\Services\ReportService;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.serverpulse.api_base' => 'https://test.example.com',
        'services.serverpulse.api_key' => 'test_key_123',
    ]);
    $_SERVER['HTTP_HOST'] = 'myapp.example.com';
});

it('sends payload and returns success on 202', function () {
    Http::fake([
        'test.example.com/*' => Http::response(['status' => 'accepted'], 202),
    ]);

    $config = new ConfigService(tempCachePath());
    $service = new ReportService;
    $result = $service->send(['test' => 'data'], $config);

    expect($result['success'])->toBeTrue();
    expect($result['status'])->toBe(202);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://test.example.com/v1/agent/report'
            && $request->method() === 'POST'
            && $request->header('X-Agent-Version')[0] === '1.0'
            && ($request->header('X-Agent-Domain')[0] ?? '') === 'myapp.example.com'
            && ($request->header('X-API-Key')[0] ?? '') === 'test_key_123'
            && $request['test'] === 'data';
    });
});

it('marks agent disabled on 410 response', function () {
    $cachePath = tempCachePath();
    file_put_contents($cachePath, json_encode(['enabled' => true]));

    Http::fake([
        'test.example.com/*' => Http::response(['error' => 'agent_disabled'], 410),
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
        'test.example.com/*' => function () {
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
