<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Collectors\LaravelCollector;
use ServerPulse\Agent\ServerPulseServiceProvider;

uses(TestCase::class);

beforeEach(function () {
    $this->app->register(ServerPulseServiceProvider::class);
    $this->app->boot();
});

it('returns all laravel metric keys', function () {
    $collector = new LaravelCollector;
    $result = $collector->collect([]);

    expect($result)->toHaveKeys([
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
});

it('returns 0 pending when queue driver is sync', function () {
    Config::set('queue.default', 'sync');

    $collector = new LaravelCollector;
    $result = $collector->collect([]);

    expect($result['queue_pending'])->toBe(0);
});

it('returns 0 pending when queue driver is null', function () {
    Config::set('queue.default', null);

    $collector = new LaravelCollector;
    $result = $collector->collect([]);

    expect($result['queue_pending'])->toBe(0);
});

it('returns 0 failed jobs when database fails', function () {
    DB::shouldReceive('table')
        ->with('failed_jobs')
        ->andThrow(new RuntimeException('Database connection failed'));

    $collector = new LaravelCollector;
    $result = $collector->collect([]);

    expect($result['queue_failed'])->toBe(0);
});

it('handles middleware counters gracefully when none have run', function () {
    $collector = new LaravelCollector;
    $result = $collector->collect([]);

    expect($result['request_count_1m'])->toBe(0);
    expect($result['response_time_avg_1m'])->toBe(0.0);
});

it('handles recent exceptions gracefully when no errors logged', function () {
    $collector = new LaravelCollector;
    $result = $collector->collect([]);

    expect($result['recent_exceptions'])->toBe(0);
});

it('detects horizon not installed', function () {
    $collector = new LaravelCollector;
    $result = $collector->collect([]);

    expect($result['horizon_enabled'])->toBeFalse();
    expect($result['horizon_stats'])->toBeNull();
});

it('detects octane not installed', function () {
    $collector = new LaravelCollector;
    $result = $collector->collect([]);

    expect($result['octane_enabled'])->toBeFalse();
    expect($result['octane_worker_count'])->toBeNull();
});
