<?php

namespace ServerPulse\Agent\Collectors;

class DomainCollector extends BaseCollector
{
    public function key(): string
    {
        return 'domain';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function doCollect(array $config): array
    {
        $appUrl = $this->resolveAppUrl();

        return [
            'app_url' => $appUrl,
            'hostname' => $this->resolveHostname(),
            'server_ip' => $this->resolveServerIp(),
            'ssl_expiry' => $this->resolveSslExpiry($appUrl),
        ];
    }

    private function resolveAppUrl(): ?string
    {
        $url = config('app.url');

        if (is_string($url) && $url !== '' && ! str_contains($url, 'localhost')) {
            return $this->normalizeUrl($url);
        }

        if (! empty($_SERVER['HTTP_HOST'])) {
            $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

            return $this->normalizeUrl($scheme.'://'.$_SERVER['HTTP_HOST']);
        }

        $envUrl = getenv('APP_URL');

        if (is_string($envUrl) && $envUrl !== '') {
            return $this->normalizeUrl($envUrl);
        }

        return null;
    }

    private function resolveHostname(): ?string
    {
        $hostname = gethostname();

        return $hostname !== false ? $hostname : null;
    }

    private function resolveServerIp(): ?string
    {
        if (! empty($_SERVER['SERVER_ADDR'])) {
            return $_SERVER['SERVER_ADDR'];
        }

        $hostname = $this->resolveHostname();

        if ($hostname !== null) {
            $ip = gethostbyname($hostname);

            if ($ip !== $hostname) {
                return $ip;
            }
        }

        return null;
    }

    private function resolveSslExpiry(?string $url): ?string
    {
        if ($url === null || ! str_starts_with($url, 'https://')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        $stream = @stream_context_create([
            'ssl' => ['capture_peer_cert' => true],
        ]);

        $client = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $stream
        );

        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($cert === null) {
            return null;
        }

        $certInfo = openssl_x509_parse($cert);

        if ($certInfo === false || ! isset($certInfo['validTo_time_t'])) {
            return null;
        }

        return date('Y-m-d', $certInfo['validTo_time_t']);
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim($url, '/');
    }
}
