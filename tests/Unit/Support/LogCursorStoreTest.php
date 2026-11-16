<?php

use ServerPulse\Agent\Support\LogCursorStore;

/**
 * A unique cursor state path inside its own temporary directory.
 */
function tempCursorPath(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sp_cursors_'.bin2hex(random_bytes(4));

    return $dir.DIRECTORY_SEPARATOR.'state.json';
}

it('returns an empty array when no state file exists', function () {
    $store = new LogCursorStore(tempCursorPath());

    expect($store->load())->toBe([]);
});

it('persists and reloads cursor state', function () {
    $path = tempCursorPath();
    $state = [
        'channel:sms' => [
            'path' => '/tmp/sms.log',
            'inode' => 1,
            'offset' => 42,
            'tail_hash' => 'abc',
            'updated_at' => 123,
        ],
    ];

    (new LogCursorStore($path))->save($state);

    expect(file_exists($path))->toBeTrue();
    expect((new LogCursorStore($path))->load())->toBe($state);

    unlink($path);
    rmdir(dirname($path));
});

it('ignores corrupt state files', function () {
    $path = tempCursorPath();
    mkdir(dirname($path), 0777, true);
    file_put_contents($path, '{not-json');

    expect((new LogCursorStore($path))->load())->toBe([]);

    unlink($path);
    rmdir(dirname($path));
});

it('creates the state directory when saving', function () {
    $path = tempCursorPath();

    (new LogCursorStore($path))->save(['x' => ['offset' => 1]]);

    expect(file_exists($path))->toBeTrue();

    unlink($path);
    rmdir(dirname($path));
});
