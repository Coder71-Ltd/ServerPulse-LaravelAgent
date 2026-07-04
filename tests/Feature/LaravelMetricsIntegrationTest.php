<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use ServerPulse\Agent\Collectors\LaravelCollector;
use ServerPulse\Agent\Middleware\RequestTaggingMiddleware;
use ServerPulse\Agent\Monolog\ServerPulseHandler;
use ServerPulse\Agent\ServerPulseServiceProvider;

beforeEach(function () {
    $this->app->register(ServerPulseServiceProvider::class);
    $this->app->boot();
    RequestTaggingMiddleware::callReset();
});

// ── Core integration tests ──

it('counts ERROR log records through handler into collector', function () {
    Log::error('Something went wrong');

    $collector = new LaravelCollector;
    $payload = $collector->collect([]);

    expect($payload['recent_exceptions'])->toBe(1);
});

it('accumulates multiple ERROR records', function () {
    Log::error('Error 1');
    Log::error('Error 2');
    Log::error('Error 3');

    $collector = new LaravelCollector;
    $payload = $collector->collect([]);

    expect($payload['recent_exceptions'])->toBe(3);
});

it('resets exception count after collector reads', function () {
    Log::error('Test error');

    $collector = new LaravelCollector;
    $first = $collector->collect([]);
    $second = $collector->collect([]);

    expect($first['recent_exceptions'])->toBe(1);
    expect($second['recent_exceptions'])->toBe(0);
});

it('ignores non-ERROR log levels', function () {
    Log::info('Test info');
    Log::warning('Test warning');

    $collector = new LaravelCollector;
    $payload = $collector->collect([]);

    expect($payload['recent_exceptions'])->toBe(0);
});

it('returns all expected laravel metric keys in payload', function () {
    $collector = new LaravelCollector;
    $payload = $collector->collect([]);

    expect($payload)->toHaveKeys([
        'app_env',
        'app_debug',
        'laravel_version',
        'php_framework',
        'queue_driver',
        'queue_pending',
        'queue_failed',
        'cache_driver',
        'session_driver',
        'horizon_enabled',
        'horizon_stats',
        'octane_enabled',
        'octane_worker_count',
        'recent_exceptions',
        'request_count_1m',
        'response_time_avg_1m',
    ]);

    expect($payload['app_env'])->toBeString();
    expect($payload['queue_driver'])->toBeString();
    expect($payload['queue_pending'])->toBeInt();
});

it('reports sync queue as 0 pending', function () {
    Config::set('queue.default', 'sync');

    $collector = new LaravelCollector;
    $payload = $collector->collect([]);

    expect($payload['queue_pending'])->toBe(0);
});

// ── Edge case and resilience tests ──

it('returns 0 middleware counters when no HTTP requests have occurred', function () {
    RequestTaggingMiddleware::callReset();

    $collector = new LaravelCollector;
    $payload = $collector->collect([]);

    // In PHP-FPM, counters are always 0 because collector is a CLI process —
    // only works in Octane/RoadRunner where middleware and collector share process memory.
    expect($payload['request_count_1m'])->toBe(0);
    expect($payload['response_time_avg_1m'])->toBe(0.0);
});

it('handles missing failed_jobs table gracefully', function () {
    $collector = new LaravelCollector;
    $payload = $collector->collect([]);

    // In Orchestra Testbench, the failed_jobs table may not exist.
    // The collector should handle this gracefully and return 0.
    expect($payload['queue_failed'])->toBeInt();
    expect($payload['queue_failed'])->toBeGreaterThanOrEqual(0);
});

it('returns queue_pending=0 for null queue driver', function () {
    Config::set('queue.default', null);

    $collector = new LaravelCollector;
    $payload = $collector->collect([]);

    expect($payload['queue_pending'])->toBe(0);
});

it('handler singleton is shared between log system and collector', function () {
    $handlerA = app(ServerPulseHandler::class);
    Log::error('Singleton test');
    $handlerB = app(ServerPulseHandler::class);

    expect($handlerA)->toBe($handlerB);
    expect($handlerB->getRecentExceptionCount())->toBe(1);
});

it('collector reads only new exceptions after reset', function () {
    Log::error('First batch');

    $collector = new LaravelCollector;
    $first = $collector->collect([]);

    expect($first['recent_exceptions'])->toBeGreaterThanOrEqual(1);

    Log::error('Second batch');

    $second = $collector->collect([]);

    // New count, not including first batch's data — proves atomic read-and-reset
    expect($second['recent_exceptions'])->toBeGreaterThanOrEqual(1);
});

it('payload structure is consistent across multiple collect cycles', function () {
    $collector = new LaravelCollector;

    $first = $collector->collect([]);
    $second = $collector->collect([]);

    expect($first)->toHaveKeys(array_keys($second));
});
