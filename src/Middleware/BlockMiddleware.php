<?php

namespace ServerPulse\Agent\Middleware;

use Closure;
use Illuminate\Http\Request;

class BlockMiddleware
{
    private ?string $cachePath = null;

    public function __construct(?string $cachePath = null)
    {
        $this->cachePath = $cachePath;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->isBlocked()) {
            return response()->json(['error' => 'Service Unavailable'], 503);
        }

        return $next($request);
    }

    private function isBlocked(): bool
    {
        $path = $this->resolveCachePath();

        // D-16: No cache file → pass through
        if (! file_exists($path)) {
            return false;
        }

        // D-18: Unreadable cache → pass through
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        // D-18: Corrupt JSON → pass through
        $config = json_decode($contents, true);
        if (! is_array($config)) {
            return false;
        }

        // D-17: blocked key missing → pass through (defaults to false)
        // Strict boolean comparison — string "true" or integer 1 does NOT trigger block
        return isset($config['blocked']) && $config['blocked'] === true;
    }

    private function resolveCachePath(): string
    {
        if ($this->cachePath !== null) {
            return $this->cachePath;
        }

        if (function_exists('storage_path')) {
            return storage_path('framework/cache/serverpulse/.sp_cache');
        }

        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'.sp_cache';
    }

    /** @internal Test accessor — matches RequestTaggingMiddleware pattern */
    public function callSetCachePath(string $path): void
    {
        $this->cachePath = $path;
    }
}
