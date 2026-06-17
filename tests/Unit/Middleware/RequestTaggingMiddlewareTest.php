<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Middleware\RequestTaggingMiddleware;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    RequestTaggingMiddleware::callReset();
});

it('passes request through without errors', function () {
    $middleware = new RequestTaggingMiddleware;
    $request = Request::create('/test');

    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
    expect($response->status())->toBe(200);
});

it('increments request counter on each call', function () {
    $middleware = new RequestTaggingMiddleware;
    $request = Request::create('/test');

    $countBefore = $middleware->callGetRequestCount();
    $middleware->handle($request, fn () => response('ok'));
    $countAfter = $middleware->callGetRequestCount();

    expect($countAfter)->toBe($countBefore + 1);
});

it('tracks response time', function () {
    $middleware = new RequestTaggingMiddleware;
    $request = Request::create('/test');
    $timeBefore = $middleware->callGetAvgResponseTime();

    $middleware->handle($request, fn () => response('ok'));

    $timeAfter = $middleware->callGetAvgResponseTime();
    expect($timeAfter)->toBeFloat();
});

it('never throws an exception', function () {
    $middleware = new RequestTaggingMiddleware;
    $request = Request::create('/test');

    $response = $middleware->handle($request, function () {
        throw new RuntimeException('Simulated failure');
    });

    expect($response->status())->toBe(200);
});

it('skips heartbeat when recent file exists', function () {
    $middleware = new RequestTaggingMiddleware;

    $heartbeatFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'.sp_heartbeat_test';
    $middleware->callSetHeartbeatPath($heartbeatFile);

    touch($heartbeatFile, time() - 10);

    $request = Request::create('/test');
    $middleware->handle($request, fn () => response('ok'));

    Http::assertNothingSent();

    @unlink($heartbeatFile);
});

it('does not crash when no heartbeat file exists', function () {
    $heartbeatFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'.sp_heartbeat_test';

    if (file_exists($heartbeatFile)) {
        unlink($heartbeatFile);
    }

    $middleware = new RequestTaggingMiddleware;
    $middleware->callSetHeartbeatPath($heartbeatFile);

    $request = Request::create('/test');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(200);

    if (file_exists($heartbeatFile)) {
        unlink($heartbeatFile);
    }
});
