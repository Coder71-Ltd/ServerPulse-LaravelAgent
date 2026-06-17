<?php

namespace ServerPulse\Agent\Collectors;

use ServerPulse\Agent\Support\ExecutesShellCommands;

class ServerCollector extends BaseCollector
{
    use ExecutesShellCommands;

    public function key(): string
    {
        return 'server';
    }

    protected function doCollect(array $config): array
    {
        return [
            'cpu_percent' => null,
            'load_avg_1m' => null,
            'load_avg_5m' => null,
            'load_avg_15m' => null,
            'cpu_cores' => null,
            'ram_total_mb' => null,
            'ram_used_mb' => null,
            'ram_percent' => null,
            'disk_total_gb' => null,
            'disk_used_gb' => null,
            'disk_percent' => null,
            'uptime_seconds' => null,
        ];
    }
}
