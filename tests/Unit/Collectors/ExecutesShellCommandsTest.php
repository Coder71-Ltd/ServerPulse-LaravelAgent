<?php

use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Support\ExecutesShellCommands;

uses(TestCase::class);

it('returns trimmed output on successful command', function () {
    $exec = new class
    {
        use ExecutesShellCommands;
    };

    $result = $exec->callSafeExec('echo hello');

    expect($result)->toBe('hello');
});

it('can detect when shell is available', function () {
    $exec = new class
    {
        use ExecutesShellCommands;
    };

    $available = $exec->callIsShellAvailable();

    expect($available)->toBeBool();
});
