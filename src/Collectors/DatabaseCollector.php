<?php

namespace ServerPulse\Agent\Collectors;

class DatabaseCollector extends BaseCollector
{
    public function key(): string
    {
        return 'database';
    }

    protected function doCollect(array $config): array
    {
        return [
            'mysql_running' => null,
            'slow_queries' => null,
            'db_driver' => config('database.default'),
            'db_connections' => array_keys(config('database.connections', [])),
        ];
    }
}
