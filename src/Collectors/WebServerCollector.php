<?php

namespace ServerPulse\Agent\Collectors;

class WebServerCollector extends BaseCollector
{
    public function key(): string
    {
        return 'web';
    }

    protected function doCollect(array $config): array
    {
        return [
            'server_type' => null,
            'running' => null,
            'active_connections' => null,
        ];
    }
}
