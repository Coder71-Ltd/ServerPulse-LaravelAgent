<?php

namespace ServerPulse\Agent\Console\Commands;

use Illuminate\Console\Command;
use ServerPulse\Agent\Collectors\Contracts\CollectorInterface;
use ServerPulse\Agent\Contracts\CommitsAfterReport;
use ServerPulse\Agent\Contracts\ProvidesExtraPayload;
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

            $collectors = $this->collectors();
            $payload = $this->buildPayload($config, $collectors);

            $this->info('Payload keys: '.implode(', ', array_keys($payload)));
            $this->info('API URL: '.$this->config->resolveApiBase().'/v1/agent/report');

            if ($this->output->isVerbose()) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $result = $this->report->send($payload, $this->config);

            if ($result['success']) {
                $this->commitCollectors($collectors);
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
     * @return array<int, CollectorInterface>
     */
    private function collectors(): array
    {
        $collectors = app()->tagged('serverpulse.collectors');

        return array_values(array_filter(
            is_array($collectors) ? $collectors : iterator_to_array($collectors, false),
            static fn (mixed $collector): bool => $collector instanceof CollectorInterface,
        ));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, CollectorInterface>  $collectors
     * @return array<string, mixed>
     */
    private function buildPayload(array $config, array $collectors): array
    {
        $payload = [
            'timestamp' => now()->toIso8601ZuluString(),
            'agent_ver' => '1.1',
            'heartbeat' => true,
        ];

        /** @var array<int, CollectorInterface> $ranCollectors */
        $ranCollectors = [];

        foreach ($collectors as $collector) {
            $key = $collector->key();

            if (isset($config['collect'][$key]) && $config['collect'][$key] === false) {
                continue;
            }

            $ranCollectors[] = $collector;

            try {
                $payload[$key] = $collector->collect($config);
            } catch (\Throwable $e) {
                $payload[$key] = [];
            }
        }

        foreach ($ranCollectors as $collector) {
            if (! $collector instanceof ProvidesExtraPayload) {
                continue;
            }

            try {
                $payload = array_merge($payload, $collector->extraPayload());
            } catch (\Throwable $e) {
                // Extra metadata is optional; never fail a report over it.
            }
        }

        return $payload;
    }

    /**
     * @param  array<int, CollectorInterface>  $collectors
     */
    private function commitCollectors(array $collectors): void
    {
        foreach ($collectors as $collector) {
            if (! $collector instanceof CommitsAfterReport) {
                continue;
            }

            try {
                if (! $collector->commit()) {
                    $this->warn("Failed to persist {$collector->key()} state; the same batch may be resent.");
                }
            } catch (\Throwable $e) {
                $this->warn("Failed to persist {$collector->key()} state; the same batch may be resent.");
            }
        }
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
