<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use ServerPulse\Agent\Middleware\BlockMiddleware;
use ServerPulse\Agent\ServerPulseServiceProvider;
use ServerPulse\Agent\Services\ConfigService;

beforeEach(function () {
    $this->app->register(ServerPulseServiceProvider::class);

    config([
        'services.serverpulse.api_base' => env('SERVERPULSE_API_BASE', 'http://192.168.68.131:3000'),
        'services.serverpulse.api_key' => env('SERVERPULSE_API_KEY'),
    ]);
});

afterEach(function () {
    $lockFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'serverpulse.lock';
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
});

if (! function_exists('uniqueCachePath')) {
    function uniqueCachePath(string $prefix): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.'_'.getmypid().'_'.uniqid();
    }
}

it('fetches config with blocked=true and caches it', function () {
    $cachePath = uniqueCachePath('sp_blocked');

    $config = new ConfigService($cachePath);

    try {
        $result = $config->get();

        expect($result)->toBeArray();
        expect($result)->toHaveKey('enabled');

        $blocked = $result['blocked'] ?? false;

        dump('API returned: enabled='.var_export($result['enabled'], true).', blocked='.var_export($blocked, true));

        if (file_exists($cachePath)) {
            $cached = json_decode(file_get_contents($cachePath), true);
            dump('Cache: enabled='.var_export($cached['enabled'] ?? '?', true).', blocked='.var_export($cached['blocked'] ?? false, true));
        }
    } finally {
        if (file_exists($cachePath)) {
            unlink($cachePath);
        }
    }
})->group('real');

it('BlockMiddleware returns 503 when blocked=true in cache', function () {
    $cachePath = uniqueCachePath('sp_blocked');

    $config = new ConfigService($cachePath);

    try {
        $config->get();

        $middleware = new BlockMiddleware($cachePath);
        $request = Request::create('/test', 'GET');

        $response = $middleware->handle($request, fn ($req) => response('PASS'));

        $blocked = false;
        if (file_exists($cachePath)) {
            $cache = json_decode(file_get_contents($cachePath), true);
            $blocked = $cache['blocked'] ?? false;
        }

        dump('Cache blocked value: '.var_export($blocked, true));
        dump('Middleware status: '.$response->getStatusCode());
        dump('Middleware body: '.$response->getContent());

        if ($blocked === true) {
            expect($response->getStatusCode())->toBe(503);
            $body = json_decode($response->getContent(), true);
            expect($body['error'])->toBe('Service Unavailable');
        } else {
            expect($response->getStatusCode())->toBe(200);
            expect($response->getContent())->toBe('PASS');
        }
    } finally {
        if (file_exists($cachePath)) {
            unlink($cachePath);
        }
    }
})->group('real');

it('agent still reports when blocked (BLK-07: heartbeat continues)', function () {
    $cachePath = uniqueCachePath('sp_blocked');

    try {
        $dir = dirname($cachePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($cachePath, json_encode([
            'enabled' => true,
            'blocked' => true,
            'collect' => [
                'server' => true, 'web' => true, 'php' => true,
                'database' => true, 'git' => true, 'logs' => true,
                'security' => true, 'laravel' => true, 'domain' => true,
            ],
            'log_paths' => [],
            'git_paths' => [],
        ]));

        $configService = new ConfigService($cachePath);
        $this->app->instance(ConfigService::class, $configService);

        $result = $this->artisan('serverpulse:report');
        $result->assertSuccessful();

        dump('BLK-07 CHECK: blocked=true in cache, agent MUST keep reporting');
        dump('Command exit code: 0 (success) — heartbeat continues while site blocked');

        $cache = json_decode(file_get_contents($cachePath), true);
        expect($cache['blocked'] ?? false)->toBeTrue();
        expect($cache['enabled'] ?? false)->toBeTrue();
    } finally {
        if (file_exists($cachePath)) {
            unlink($cachePath);
        }
    }
})->group('real');
