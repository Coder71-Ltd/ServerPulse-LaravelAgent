<?php

namespace ServerPulse\Agent\Services;

use Illuminate\Support\Facades\Http;

class ReportService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: ?int, error: ?string}
     */
    public function send(array $payload, ConfigService $config): array
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Agent-Version' => '1.0',
                'X-API-Key' => config('services.serverpulse.api_key'),
            ])->withOptions(['timeout' => 15, 'connect_timeout' => 5])
                ->post($config->resolveApiBase().'/v1/agent/report', $payload);

            $status = $response->status();

            if ($status === 202 || $status === 200) {
                return ['success' => true, 'status' => $status, 'error' => null];
            }

            if ($status === 410) {
                $config->markDisabled();

                return ['success' => false, 'status' => $status, 'error' => 'agent_disabled'];
            }

            $body = mb_substr((string) $response->body(), 0, 1000);

            return ['success' => false, 'status' => $status, 'error' => "unexpected_status ({$status}): {$body}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'status' => null, 'error' => $e->getMessage()];
        }
    }
}
