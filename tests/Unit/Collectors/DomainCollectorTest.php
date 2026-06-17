<?php

use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Collectors\DomainCollector;

uses(TestCase::class);

beforeEach(function () {
    $_SERVER['SERVER_ADDR'] = '203.0.113.1';
    putenv('APP_URL');
});

it('uses config app.url when valid', function () {
    config()->set('app.url', 'https://example.com');

    $collector = new DomainCollector;
    $result = $collector->collect([]);

    expect($result['app_url'])->toBe('https://example.com');
});

it('strips trailing slash from app_url', function () {
    config()->set('app.url', 'https://example.com/');

    $collector = new DomainCollector;
    $result = $collector->collect([]);

    expect($result['app_url'])->toBe('https://example.com');
});

it('falls back to HTTP_HOST when config is localhost', function () {
    config()->set('app.url', 'http://localhost');
    $_SERVER['HTTP_HOST'] = 'real-domain.com';
    $_SERVER['HTTPS'] = 'on';

    $collector = new DomainCollector;
    $result = $collector->collect([]);

    expect($result['app_url'])->toBe('https://real-domain.com');

    unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
});

it('falls back to APP_URL env var in CLI', function () {
    config()->set('app.url', null);
    putenv('APP_URL=https://env-domain.com');

    $collector = new DomainCollector;
    $result = $collector->collect([]);

    expect($result['app_url'])->toBe('https://env-domain.com');
});

it('returns null for app_url when all sources fail', function () {
    config()->set('app.url', null);
    putenv('APP_URL');
    unset($_SERVER['HTTP_HOST']);

    $collector = new DomainCollector;
    $result = $collector->collect([]);

    expect($result['app_url'])->toBeNull();
    expect($result['hostname'])->toBeString();
    expect($result['server_ip'])->toBeString();
});

it('collects server_ip falling back to gethostbyname in CLI', function () {
    unset($_SERVER['SERVER_ADDR']);

    $collector = new DomainCollector;
    $result = $collector->collect([]);

    expect($result['server_ip'])->toBeString();
    expect($result['server_ip'])->not->toBeEmpty();
});

it('returns null for ssl_expiry on http url', function () {
    config()->set('app.url', 'http://example.com');

    $collector = new DomainCollector;
    $result = $collector->collect([]);

    expect($result['ssl_expiry'])->toBeNull();
});
