<?php

namespace ServerPulse\Agent\Collectors;

class SecurityCollector extends BaseCollector
{
    public function key(): string
    {
        return 'security';
    }

    protected function doCollect(array $config): array
    {
        return [
            'failed_ssh_1h' => null,
        ];
    }
}
