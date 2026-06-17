<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Services\ConfigService;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
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
        'webhook.site/*' => Http::response($apiResponse, 200),
    ]);

    $service = new ConfigService(tempCachePath());
    $result = $service->get();

    expect($result)->toBe($apiResponse);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://webhook.site/574b882b-7f6b-479c-b216-5a411bc1192a/v1/agent/config'
            && $request->method() === 'GET'
            && $request->header('X-Agent-Version')[0] === '1.0';
    });

    $cachedContents = json_decode(file_get_contents(tempCachePath()), true);
    expect($cachedContents)->toBe($apiResponse);
});

it('writes disabled config and returns it on HTTP 410', function () {
    Http::fake([
        'webhook.site/*' => Http::response(['error' => 'agent_disabled'], 410),
    ]);

    $service = new ConfigService(tempCachePath());
    $result = $service->get();

    expect($result['enabled'])->toBeFalse();

    $cached = json_decode(file_get_contents(tempCachePath()), true);
    expect($cached['enabled'])->toBeFalse();
});

it('uses stale cache when API fails', function () {
    $staleCache = ['enabled' => true, 'log_paths' => [['label' => 'old', 'path' => '/old/path']], 'git_paths' => [], 'collect' => []];
    writeTestCache($staleCache, mtimeAgo: 400);

    Http::fake([
        'webhook.site/*' => Http::response(null, 500),
    ]);

    $service = new ConfigService(tempCachePath());
    $result = $service->get();

    expect($result)->toBe($staleCache);
});

it('uses fallback defaults when no cache and API is unreachable', function () {
    Http::fake([
        'webhook.site/*' => Http::response(null, 500),
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
        'webhook.site/*' => Http::response($apiResponse, 200),
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
