<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ServerPulse\Agent\ServerPulseServiceProvider;
use ServerPulse\Agent\Services\ConfigService;

beforeEach(function () {
    $this->app->register(ServerPulseServiceProvider::class);

    $apiKey = env('SERVERPULSE_API_KEY');

    if (empty($apiKey)) {
        throw new RuntimeException(
            'SERVERPULSE_API_KEY env var required. Run: $env:SERVERPULSE_API_KEY="your-key"'
        );
    }

    config([
        'services.serverpulse.api_base' => env('SERVERPULSE_API_BASE', 'http://192.168.68.131:3000'),
        'services.serverpulse.api_key' => $apiKey,
    ]);
});

afterEach(function () {
    $lockFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'serverpulse.lock';
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
});

it('fetches config from live API and handles response correctly', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sp_real_test_cache';
    if (file_exists($cachePath)) {
        unlink($cachePath);
    }

    $config = new ConfigService($cachePath);
    $result = $config->get();

    expect($result)->toBeArray();
    expect($result)->toHaveKey('enabled');

    if ($result['enabled'] === true) {
        expect($result)->toHaveKey('collect');
        expect($result['collect'])->toHaveKeys([
            'server', 'web', 'php', 'database',
            'git', 'logs', 'security', 'laravel', 'domain',
        ]);
    }

    $cached = json_decode(file_get_contents($cachePath), true);
    expect($cached['enabled'])->toBe($result['enabled']);

    if (file_exists($cachePath)) {
        unlink($cachePath);
    }
})->group('real');

it('caches config and does not re-fetch within TTL', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sp_real_test_cache';
    if (file_exists($cachePath)) {
        unlink($cachePath);
    }

    $config = new ConfigService($cachePath);
    $first = $config->get();
    expect($first)->toBeArray();
    expect($first)->toHaveKey('enabled');

    Http::preventStrayRequests();

    $second = $config->get();
    expect($second)->toBeArray();
    expect($second)->toEqual($first);

    Http::assertNothingSent();

    if (file_exists($cachePath)) {
        unlink($cachePath);
    }
})->group('real');

it('runs report command and exits gracefully', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sp_real_test_cache';
    if (file_exists($cachePath)) {
        unlink($cachePath);
    }

    $this->app->instance(ConfigService::class, new ConfigService($cachePath));

    $result = $this->artisan('serverpulse:report');

    $result->assertSuccessful();

    if (file_exists($cachePath)) {
        $cached = json_decode(file_get_contents($cachePath), true);
        if (($cached['enabled'] ?? true) === false) {
            dump('NOTE: Agent is DISABLED — report cycle exited without sending (expected)');
        }
        unlink($cachePath);
    }
})->group('real');

it('resolves correct API base URL from config', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sp_real_test_cache';
    if (file_exists($cachePath)) {
        unlink($cachePath);
    }

    $config = new ConfigService($cachePath);

    $apiBase = $config->resolveApiBase();

    expect($apiBase)->toStartWith('http');

    if (file_exists($cachePath)) {
        unlink($cachePath);
    }
})->group('real');

it('handles 410 disabled state — config returns enabled=false', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sp_410_test_cache';
    if (file_exists($cachePath)) {
        unlink($cachePath);
    }

    $config = new ConfigService($cachePath);
    $result = $config->get();

    expect($result)->toBeArray();

    if (($result['enabled'] ?? true) === false) {
        expect($result)->not->toHaveKey('collect');

        $this->app->instance(ConfigService::class, $config);
        $this->artisan('serverpulse:report')->assertSuccessful();
    }

    if (file_exists($cachePath)) {
        unlink($cachePath);
    }
})->group('real');
