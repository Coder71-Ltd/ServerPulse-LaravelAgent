<?php

namespace ServerPulse\Agent\Collectors\Contracts;

interface CollectorInterface
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function collect(array $config): array;

    public function key(): string;
}
