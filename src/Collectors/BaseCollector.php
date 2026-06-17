<?php

namespace ServerPulse\Agent\Collectors;

use ServerPulse\Agent\Collectors\Contracts\CollectorInterface;

abstract class BaseCollector implements CollectorInterface
{
    /**
     * Public collect method with error handling wrapper.
     * Catches all exceptions — never bubbles up to the host app.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    final public function collect(array $config): array
    {
        try {
            return $this->doCollect($config);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Subclass hook: implement the actual collection logic.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    abstract protected function doCollect(array $config): array;
}
