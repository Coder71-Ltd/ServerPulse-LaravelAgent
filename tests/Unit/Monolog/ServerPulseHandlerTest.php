<?php

use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Monolog\ServerPulseHandler;
use ServerPulse\Agent\ServerPulseServiceProvider;

uses(TestCase::class);

beforeEach(function () {
    $this->handler = new ServerPulseHandler;
});

// ── Unit tests (handler behaviour, no provider needed) ──

it('counts ERROR level records', function () {
    $record = new LogRecord(
        new DateTimeImmutable,
        'test',
        Level::Error,
        'Something went wrong'
    );

    $this->handler->handle($record);

    expect($this->handler->getRecentExceptionCount())->toBe(1);
});

it('ignores non-ERROR records', function () {
    $levels = [Level::Debug, Level::Info, Level::Warning];

    foreach ($levels as $level) {
        $record = new LogRecord(
            new DateTimeImmutable,
            'test',
            $level,
            'Not an error'
        );

        $this->handler->handle($record);
    }

    expect($this->handler->getRecentExceptionCount())->toBe(0);
});

it('resets counter after read', function () {
    for ($i = 0; $i < 3; $i++) {
        $record = new LogRecord(
            new DateTimeImmutable,
            'test',
            Level::Error,
            "Error $i"
        );

        $this->handler->handle($record);
    }

    expect($this->handler->getRecentExceptionCount())->toBe(3);
    expect($this->handler->getRecentExceptionCount())->toBe(0);
});

it('allows bubbling by returning false', function () {
    $record = new LogRecord(
        new DateTimeImmutable,
        'test',
        Level::Error,
        'Bubble test'
    );

    expect($this->handler->handle($record))->toBeFalse();
});

it('counts CRITICAL and ALERT and EMERGENCY as errors', function () {
    $highLevels = [
        Level::Critical,
        Level::Alert,
        Level::Emergency,
    ];

    foreach ($highLevels as $level) {
        $record = new LogRecord(
            new DateTimeImmutable,
            'test',
            $level,
            'High severity'
        );

        $this->handler->handle($record);
    }

    expect($this->handler->getRecentExceptionCount())->toBe(3);
});

// ── Provider integration tests ──

it('is registered as singleton in the service provider', function () {
    $this->app->register(ServerPulseServiceProvider::class);

    $handlerA = $this->app->make(ServerPulseHandler::class);
    $handlerB = $this->app->make(ServerPulseHandler::class);

    expect($handlerA)->toBe($handlerB);
});

it('auto-registers on the log stack', function () {
    $this->app->register(ServerPulseServiceProvider::class);

    $this->app->boot();

    Log::error('Test error message');

    $handler = $this->app->make(ServerPulseHandler::class);

    expect($handler->getRecentExceptionCount())->toBe(1);
});
