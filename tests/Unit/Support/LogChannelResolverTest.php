<?php

use ServerPulse\Agent\Support\LogChannelResolver;

/**
 * Create a unique temporary directory for channel fixtures.
 */
function makeChannelDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sp_channels_'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    return $dir;
}

it('knows which channels are configured', function () {
    $resolver = new LogChannelResolver([
        'sms' => ['driver' => 'single', 'path' => '/tmp/sms.log'],
    ]);

    expect($resolver->has('sms'))->toBeTrue();
    expect($resolver->has('nope'))->toBeFalse();
});

it('resolves a single channel to its configured path', function () {
    $resolver = new LogChannelResolver([
        'app' => ['driver' => 'single', 'path' => '/var/www/storage/logs/app.log'],
    ]);

    $targets = $resolver->resolve('app');

    expect($targets)->toHaveCount(1);
    expect($targets[0]['channel'])->toBe('app');
    expect($targets[0]['driver'])->toBe('single');
    expect($targets[0]['path'])->toBe('/var/www/storage/logs/app.log');
    expect($targets[0]['file_based'])->toBeTrue();
});

it('resolves a daily channel to the newest rotated file', function () {
    $dir = makeChannelDir();
    $older = $dir.'/sms-2026-11-14.log';
    $newer = $dir.'/sms-2026-11-15.log';

    file_put_contents($older, "old\n");
    file_put_contents($newer, "new\n");
    touch($older, time() - 3600);
    touch($newer, time());

    $resolver = new LogChannelResolver([
        'sms' => ['driver' => 'daily', 'path' => $dir.'/sms.log'],
    ]);

    $targets = $resolver->resolve('sms');

    expect($targets[0]['path'])->toBe($newer);

    unlink($older);
    unlink($newer);
    rmdir($dir);
});

it("falls back to today's daily filename when no file exists", function () {
    $dir = makeChannelDir();

    $resolver = new LogChannelResolver([
        'sms' => ['driver' => 'daily', 'path' => $dir.'/sms.log'],
    ]);

    $targets = $resolver->resolve('sms');

    expect($targets[0]['path'])->toBe($dir.'/sms-'.date('Y-m-d').'.log');
    expect($targets[0]['exists'])->toBeFalse();

    rmdir($dir);
});

it('expands a stack channel into its file-based members', function () {
    $dir = makeChannelDir();

    $resolver = new LogChannelResolver([
        'sms' => ['driver' => 'daily', 'path' => $dir.'/sms.log'],
        'app' => ['driver' => 'single', 'path' => $dir.'/app.log'],
        'stack' => ['driver' => 'stack', 'channels' => ['sms', 'app']],
    ]);

    $targets = $resolver->resolve('stack');

    expect(array_column($targets, 'channel'))->toBe(['sms', 'app']);

    rmdir($dir);
});

it('marks non-file drivers as not file based', function () {
    $resolver = new LogChannelResolver([
        'slack' => ['driver' => 'slack', 'url' => 'https://example.com'],
    ]);

    $targets = $resolver->resolve('slack');

    expect($targets[0]['file_based'])->toBeFalse();
    expect($targets[0]['error'])->toBe('not_file_based');
});

it('reports unknown channels', function () {
    $resolver = new LogChannelResolver([]);

    $targets = $resolver->resolve('ghost');

    expect($targets[0]['file_based'])->toBeFalse();
    expect($targets[0]['error'])->toBe('unknown_channel');
});

it('discovers every configured channel', function () {
    $dir = makeChannelDir();

    $resolver = new LogChannelResolver([
        'sms' => ['driver' => 'daily', 'path' => $dir.'/sms.log'],
        'app' => ['driver' => 'single', 'path' => $dir.'/app.log'],
        'slack' => ['driver' => 'slack', 'url' => 'https://example.com'],
    ]);

    $discovered = $resolver->discover();
    $names = array_column($discovered, 'name');

    expect($names)->toContain('sms');
    expect($names)->toContain('app');
    expect($names)->toContain('slack');

    $slack = collect($discovered)->firstWhere('name', 'slack');

    expect($slack['file_based'])->toBeFalse();

    rmdir($dir);
});
