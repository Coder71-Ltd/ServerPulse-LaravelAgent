<?php

namespace ServerPulse\Agent\Collectors;

class LogsCollector extends BaseCollector
{
    public function key(): string
    {
        return 'logs';
    }

    protected function doCollect(array $config): array
    {
        return [];
    }
}
