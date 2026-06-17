<?php

namespace ServerPulse\Agent\Collectors;

class PhpCollector extends BaseCollector
{
    public function key(): string
    {
        return 'php';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function doCollect(array $config): array
    {
        return [
            'version' => PHP_VERSION,
            'version_major' => PHP_MAJOR_VERSION,
            'version_minor' => PHP_MINOR_VERSION,
            'extensions' => $this->getKeyExtensions(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'opcache_enabled' => function_exists('opcache_get_status'),
        ];
    }

    /**
     * @return list<string>
     */
    private function getKeyExtensions(): array
    {
        $key = ['pdo', 'mysqlnd', 'redis', 'gd', 'curl', 'json', 'mbstring', 'opcache', 'intl', 'xml'];

        return array_values(array_intersect($key, get_loaded_extensions()));
    }
}
