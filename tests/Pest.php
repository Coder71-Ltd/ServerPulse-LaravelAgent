<?php

use Orchestra\Testbench\TestCase;

require_once __DIR__.'/Helpers.php';

pest()->extend(TestCase::class)
    ->in('Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
