<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Services\ConfigService;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.serverpulse.api_base' => 'https://test.example.com',
        'services.serverpulse.api_key' => 'test_key_123',
    ]);
});

function writeTestCache(array $data, ?int $mtimeAgo = null): void
{
    $dir = dirname(tempCachePath());
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(tempCachePath(), json_encode($data));
    if ($mtimeAgo !== null) {
        touch(tempCachePath(), time() - $mtimeAgo);
    }
}

function cleanTestCache(): void
{
    $path = tempCachePath();
    if (file_exists($path)) {
        unlink($path);
    }
}

beforeEach(function () {
    cleanTestCache();
});

afterEach(function () {
    cleanTestCache();
});

it('returns cached config when cache is fresh', function () {
    $cached = ['enabled' => true, 'log_paths' => [['label' => 'app', 'path' => '/var/log/app.log']], 'git_paths' => [], 'collect' => ['server' => false]];
    writeTestCache($cached, mtimeAgo: 100);

    $service = new ConfigService(tempCachePath());
    $result = $service->get();

    expect($result)->toBe($cached);
    Http::assertNothingSent();
});

it('fetches from API when cache is stale and writes fresh cache', function () {
    writeTestCache(['enabled' => true, 'log_paths' => [], 'git_paths' => [], 'collect' => []], mtimeAgo: 400);

    $apiResponse = ['enabled' => true, 'log_paths' => [['label' => 'laravel', 'path' => '/var/www/storage/logs/laravel.log']], 'git_paths' => [['label' => 'main', 'path' => '/var/www']], 'collect' => ['server' => true]];

    Http::fake([
        'test.example.com/*' => Http::response($apiResponse, 200),
    ]);

    $service = new ConfigService(tempCachePath());
    $result = $service->get();

    expect($result)->toBe($apiResponse);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://test.example.com/v1/agent/config'
            && $request->method() === 'GET'
            && $request->header('X-Agent-Version')[0] === '1.0'
            && is_string($request->header('X-Agent-Domain')[0] ?? null)
            && ($request->header('X-API-Key')[0] ?? '') === 'test_key_123';
    });

    $cachedContents = json_decode(file_get_contents(tempCachePath()), true);
    expect($cachedContents)->toBe($apiResponse);
});

it('writes disabled config and returns it on HTTP 410', function () {
    Http::fake([
        'test.example.com/*' => Http::response(['error' => 'agent_disabled'], 410),
    ]);

    $service = new ConfigService(tempCachePath());
    $result = $service->get();

    expect($result['enabled'])->toBeFalse();

    $cached = json_decode(file_get_contents(tempCachePath()), true);
    expect($cached['enabled'])->toBeFalse();
});

it('uses stale cache when API throws network error', function () {
    $staleCache = ['enabled' => true, 'log_paths' => [['label' => 'old', 'path' => '/old/path']], 'git_paths' => [], 'collect' => []];
    writeTestCache($staleCache, mtimeAgo: 400);

    Http::fake([
        'test.example.com/*' => function () {
            throw new Exception('Connection refused');
        },
    ]);

    $service = new ConfigService(tempCachePath());
    $result = $service->get();

    expect($result)->toBe($staleCache);
});

it('uses fallback defaults when no cache and API is unreachable', function () {
    Http::fake([
        'test.example.com/*' => Http::response(null, 500),
    ]);

    $service = new ConfigService(tempCachePath());
    $result = $service->get();

    expect($result['enabled'])->toBeTrue();
    expect($result['log_paths'])->toBe([]);
    expect($result['git_paths'])->toBe([]);
    expect($result['collect'])->toHaveKeys(['server', 'web', 'php', 'database', 'git', 'logs', 'security', 'laravel', 'domain']);
});

it('uses cache at 299 seconds (within TTL) but fetches at 300 seconds', function () {
    $cached = ['enabled' => true, 'log_paths' => [], 'git_paths' => [], 'collect' => []];

    writeTestCache($cached, mtimeAgo: 299);
    $service = new ConfigService(tempCachePath());
    $withinResult = $service->get();
    expect($withinResult)->toBe($cached);
    Http::assertNothingSent();

    writeTestCache($cached, mtimeAgo: 300);

    $apiResponse = ['enabled' => true, 'log_paths' => [], 'git_paths' => [], 'collect' => ['server' => true]];
    Http::fake([
        'test.example.com/*' => Http::response($apiResponse, 200),
    ]);

    $expiredResult = $service->get();
    expect($expiredResult)->toBe($apiResponse);
    Http::assertSentCount(1);
});

it('marks the agent as disabled', function () {
    $service = new ConfigService(tempCachePath());
    $service->markDisabled();

    $cached = json_decode(file_get_contents(tempCachePath()), true);
    expect($cached['enabled'])->toBeFalse();

    $result = $service->get();
    expect($result['enabled'])->toBeFalse();
});

it('resolves api key from cached agent config first', function () {
    writeTestCache(['enabled' => true, 'api_key' => 'cached_key_abc'], mtimeAgo: 100);

    $service = new ConfigService(tempCachePath());

    expect($service->resolveApiKey())->toBe('cached_key_abc');
});

it('falls back to configured api key when cache has none', function () {
    writeTestCache(['enabled' => true], mtimeAgo: 100);

    $service = new ConfigService(tempCachePath());

    expect($service->resolveApiKey())->toBe('test_key_123');
});

it('returns null api key when neither cache nor config provide one', function () {
    writeTestCache(['enabled' => true], mtimeAgo: 100);

    config(['services.serverpulse.api_key' => null]);

    $service = new ConfigService(tempCachePath());

    expect($service->resolveApiKey())->toBeNull();
});

it('prefers stale cache api key even when cache is expired', function () {
    writeTestCache(['enabled' => true, 'api_key' => 'stale_key_xyz'], mtimeAgo: 3600);

    $service = new ConfigService(tempCachePath());

    expect($service->resolveApiKey())->toBe('stale_key_xyz');
});

it('resolves agent domain from app.url host first', function () {
    config(['app.url' => 'https://client-site.com']);
    $_SERVER['HTTP_HOST'] = 'example.com';

    $service = new ConfigService(tempCachePath());

    expect($service->resolveAgentDomain())->toBe('client-site.com');

    unset($_SERVER['HTTP_HOST']);
});

it('falls back to HTTP_HOST when app.url has no host', function () {
    config(['app.url' => null]);
    $_SERVER['HTTP_HOST'] = 'example.com';

    $service = new ConfigService(tempCachePath());

    expect($service->resolveAgentDomain())->toBe('example.com');

    unset($_SERVER['HTTP_HOST']);
});

it('sends api key header on config fetch when available', function () {
    Http::fake([
        'test.example.com/*' => Http::response(['enabled' => true], 200),
    ]);

    $service = new ConfigService(tempCachePath());
    $service->get();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://test.example.com/v1/agent/config'
            && $request->header('X-API-Key')[0] === 'test_key_123';
    });
});
