<?php

use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Collectors\BaseCollector;
use ServerPulse\Agent\Collectors\Contracts\CollectorInterface;

uses(TestCase::class);

it('returns doCollect result on success', function () {
    $collector = new class extends BaseCollector
    {
        public function key(): string
        {
            return 'test';
        }

        protected function doCollect(array $config): array
        {
            return ['status' => 'ok', 'value' => 42];
        }
    };

    $result = $collector->collect([]);

    expect($result)->toBe(['status' => 'ok', 'value' => 42]);
});

it('returns empty array when doCollect throws', function () {
    $collector = new class extends BaseCollector
    {
        public function key(): string
        {
            return 'test';
        }

        protected function doCollect(array $config): array
        {
            throw new RuntimeException('Something went wrong');
        }
    };

    $result = $collector->collect([]);

    expect($result)->toBe([]);
});

it('implements CollectorInterface', function () {
    $collector = new class extends BaseCollector
    {
        public function key(): string
        {
            return 'test';
        }

        protected function doCollect(array $config): array
        {
            return [];
        }
    };

    expect($collector)->toBeInstanceOf(CollectorInterface::class);
});
