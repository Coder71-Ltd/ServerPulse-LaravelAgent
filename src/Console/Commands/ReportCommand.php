<?php

namespace ServerPulse\Agent\Console\Commands;

use Illuminate\Console\Command;
use ServerPulse\Agent\Services\ConfigService;
use ServerPulse\Agent\Services\ReportService;

class ReportCommand extends Command
{
    protected $signature = 'serverpulse:report';

    protected $description = 'Collect and report server metrics to ServerPulse';

    private string $lockFile;

    public function __construct(
        private readonly ConfigService $config,
        private readonly ReportService $report,
    ) {
        parent::__construct();

        $this->lockFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'serverpulse.lock';
    }

    public function handle(): int
    {
        if (! $this->acquireLock()) {
            $this->info('Another instance is already running.');

            return self::SUCCESS;
        }

        try {
            $config = $this->config->get();

            if (($config['enabled'] ?? true) === false) {
                $this->info('Agent is disabled.');

                return self::SUCCESS;
            }

            $payload = $this->buildPayload($config);

            $result = $this->report->send($payload, $this->config);

            if ($result['success']) {
                $this->info('Report sent successfully.');

                return self::SUCCESS;
            }

            if ($result['status'] === 410) {
                $this->warn('Agent disabled by server (410).');
            } else {
                $this->warn('Report failed: '.($result['error'] ?? 'unknown error'));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Unexpected error: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function buildPayload(array $config): array
    {
        $payload = [
            'timestamp' => now()->toIso8601ZuluString(),
            'agent_ver' => '1.0',
            'heartbeat' => true,
        ];

        $collectors = app()->tagged('serverpulse.collectors');

        foreach ($collectors as $collector) {
            $key = $collector->key();

            if (isset($config['collect'][$key]) && $config['collect'][$key] === false) {
                continue;
            }

            try {
                $payload[$key] = $collector->collect($config);
            } catch (\Throwable $e) {
                $payload[$key] = [];
            }
        }

        return $payload;
    }

    private function acquireLock(): bool
    {
        if (file_exists($this->lockFile)) {
            $age = time() - filemtime($this->lockFile);

            if ($age < 55) {
                return false;
            }
        }

        $dir = dirname($this->lockFile);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->lockFile, (string) getmypid());

        return true;
    }

    private function releaseLock(): void
    {
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }
}
