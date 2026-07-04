<?php

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Middleware\BlockMiddleware;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a temporary cache file with the given data and return its absolute path.
 *
 * @param  mixed  $data  Scalar or array — non-string values are JSON-encoded.
 */
function createTempCacheFile(string $filename, mixed $data): string
{
    $path = tempCachePath($filename);
    file_put_contents($path, is_string($data) ? $data : json_encode($data));

    return $path;
}

/**
 * Remove a temp cache file if it exists.
 */
function removeTempCacheFile(string $filename): void
{
    $path = tempCachePath($filename);
    if (file_exists($path)) {
        @unlink($path);
    }
}

// ---------------------------------------------------------------------------
// Setup / teardown
// ---------------------------------------------------------------------------

$tempFiles = [];

beforeEach(function () use (&$tempFiles) {
    $tempFiles = [];
});

afterEach(function () use (&$tempFiles) {
    foreach ($tempFiles as $file) {
        removeTempCacheFile($file);
    }
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('passes through when no cache file exists', function () {
    $middleware = new BlockMiddleware(tempCachePath('no-such-file-test'));

    $request = Request::create('/test');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(200);
    expect($response->getContent())->toBe('ok');
});

it('blocks with 503 when blocked key is true', function () use (&$tempFiles) {
    $filename = 'blocked-true-test';
    $path = createTempCacheFile($filename, ['blocked' => true]);
    $tempFiles[] = $filename;

    $middleware = new BlockMiddleware($path);

    $request = Request::create('/test');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(503);
    expect($response->headers->get('Content-Type'))->toContain('application/json');

    $body = json_decode($response->getContent(), true);
    expect($body)->toBe(['error' => 'Service Unavailable']);
});

it('passes through when blocked key is false', function () use (&$tempFiles) {
    $filename = 'blocked-false-test';
    $path = createTempCacheFile($filename, ['blocked' => false]);
    $tempFiles[] = $filename;

    $middleware = new BlockMiddleware($path);

    $request = Request::create('/test');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(200);
    expect($response->getContent())->toBe('ok');
});

it('passes through when blocked key is missing', function () use (&$tempFiles) {
    $filename = 'blocked-missing-test';
    $path = createTempCacheFile($filename, ['enabled' => true]);
    $tempFiles[] = $filename;

    $middleware = new BlockMiddleware($path);

    $request = Request::create('/test');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(200);
    expect($response->getContent())->toBe('ok');
});

it('passes through on corrupt cache file', function () use (&$tempFiles) {
    $filename = 'corrupt-cache-test';
    $path = createTempCacheFile($filename, 'not valid json at all');
    $tempFiles[] = $filename;

    $middleware = new BlockMiddleware($path);

    $request = Request::create('/test');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(200);
    expect($response->getContent())->toBe('ok');
});

it('passes through when json_decode returns null', function () use (&$tempFiles) {
    $filename = 'null-json-test';
    $path = createTempCacheFile($filename, 'null');
    $tempFiles[] = $filename;

    $middleware = new BlockMiddleware($path);

    $request = Request::create('/test');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(200);
    expect($response->getContent())->toBe('ok');
});

it('passes through when cache file is empty object', function () use (&$tempFiles) {
    $filename = 'empty-object-test';
    $path = createTempCacheFile($filename, '{}');
    $tempFiles[] = $filename;

    $middleware = new BlockMiddleware($path);

    $request = Request::create('/test');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(200);
    expect($response->getContent())->toBe('ok');
});

it('passes through on unreadable cache file', function () {
    $middleware = new BlockMiddleware(tempCachePath('non-existent-file'));

    $request = Request::create('/test');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(200);
    expect($response->getContent())->toBe('ok');
});

it('blocks with strict boolean equality only', function () use (&$tempFiles) {
    // String "true" must NOT trigger a block — only boolean true does
    $filename = 'strict-bool-test';
    $path = createTempCacheFile($filename, ['blocked' => 'true']);
    $tempFiles[] = $filename;

    $middleware = new BlockMiddleware($path);

    $request = Request::create('/test');
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(200);
    expect($response->getContent())->toBe('ok');
});
