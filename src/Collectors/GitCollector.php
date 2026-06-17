<?php

namespace ServerPulse\Agent\Collectors;

class GitCollector extends BaseCollector
{
    public function key(): string
    {
        return 'git';
    }

    protected function doCollect(array $config): array
    {
        return [];
    }
}
