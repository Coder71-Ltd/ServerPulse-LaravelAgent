<?php

namespace ServerPulse\Agent\Monolog;

use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;

class ServerPulseHandler extends AbstractHandler
{
    private int $exceptionCount = 0;

    public function __construct()
    {
        parent::__construct(Level::Error, true);
    }

    public function handle(LogRecord $record): bool
    {
        if ($record->level->value >= Level::Error->value) {
            $this->exceptionCount++;
        }

        return false;
    }

    public function getRecentExceptionCount(): int
    {
        $count = $this->exceptionCount;
        $this->exceptionCount = 0;

        return $count;
    }
}
