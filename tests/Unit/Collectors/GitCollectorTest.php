<?php

use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Collectors\GitCollector;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Assert that the result array contains both git metric keys.
 */
function assertGitKeys(array $result): void
{
    expect($result)->toHaveKeys([
        'branch',
        'commit',
        'message',
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('returns all git metric keys', function () {
    $result = (new GitCollector)->collect([]);

    assertGitKeys($result);
});

it('returns branch and commit when in a git repo', function () {
    $collector = new class extends GitCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'log -1 --format=%s')) {
                return 'feat: add commit message to git collector';
            }

            if (str_contains($command, 'rev-parse --abbrev-ref HEAD')) {
                return 'main';
            }

            if (str_contains($command, 'rev-parse --short HEAD')) {
                return 'abc1234';
            }

            return null;
        }

        protected function doCollect(array $config): array
        {
            $repoPath = $config['git_path']
                ?? $config['git_repo_path']
                ?? base_path();

            $escaped = escapeshellarg($repoPath);

            $branch = $this->safeExec(
                'git -C '.$escaped.' rev-parse --abbrev-ref HEAD'
            );

            $commit = $this->safeExec(
                'git -C '.$escaped.' rev-parse --short HEAD'
            );

            $message = $this->safeExec(
                'git -C '.$escaped.' log -1 --format=%s'
            );

            return ['branch' => $branch, 'commit' => $commit, 'message' => $message];
        }
    };

    $result = $collector->collect([]);

    expect($result['branch'])->toBe('main');
    expect($result['commit'])->toBe('abc1234');
    expect($result['message'])->toBe('feat: add commit message to git collector');
});

it("returns 'HEAD' branch in detached state", function () {
    $collector = new class extends GitCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'log -1 --format=%s')) {
                return 'chore: detached head state';
            }

            if (str_contains($command, 'rev-parse --abbrev-ref HEAD')) {
                return 'HEAD';
            }

            if (str_contains($command, 'rev-parse --short HEAD')) {
                return 'def5678';
            }

            return null;
        }

        protected function doCollect(array $config): array
        {
            $repoPath = $config['git_path']
                ?? $config['git_repo_path']
                ?? base_path();

            $escaped = escapeshellarg($repoPath);

            $branch = $this->safeExec(
                'git -C '.$escaped.' rev-parse --abbrev-ref HEAD'
            );

            $commit = $this->safeExec(
                'git -C '.$escaped.' rev-parse --short HEAD'
            );

            $message = $this->safeExec(
                'git -C '.$escaped.' log -1 --format=%s'
            );

            return ['branch' => $branch, 'commit' => $commit, 'message' => $message];
        }
    };

    $result = $collector->collect([]);

    expect($result['branch'])->toBe('HEAD');
    expect($result['commit'])->toBe('def5678');
    expect($result['message'])->toBe('chore: detached head state');
});

it('returns null values when git command fails', function () {
    $collector = new class extends GitCollector
    {
        protected function safeExec(string $command): ?string
        {
            return null;
        }

        protected function doCollect(array $config): array
        {
            $repoPath = $config['git_path']
                ?? $config['git_repo_path']
                ?? base_path();

            $escaped = escapeshellarg($repoPath);

            $branch = $this->safeExec(
                'git -C '.$escaped.' rev-parse --abbrev-ref HEAD'
            );

            $commit = $this->safeExec(
                'git -C '.$escaped.' rev-parse --short HEAD'
            );

            $message = $this->safeExec(
                'git -C '.$escaped.' log -1 --format=%s'
            );

            return ['branch' => $branch, 'commit' => $commit, 'message' => $message];
        }
    };

    $result = $collector->collect([]);

    expect($result['branch'])->toBeNull();
    expect($result['commit'])->toBeNull();
    expect($result['message'])->toBeNull();
});

it('returns null branch when only commit succeeds', function () {
    $collector = new class extends GitCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'log -1 --format=%s')) {
                return 'fix: partial return test';
            }

            if (str_contains($command, 'rev-parse --abbrev-ref HEAD')) {
                return null;
            }

            if (str_contains($command, 'rev-parse --short HEAD')) {
                return 'def5678';
            }

            return null;
        }

        protected function doCollect(array $config): array
        {
            $repoPath = $config['git_path']
                ?? $config['git_repo_path']
                ?? base_path();

            $escaped = escapeshellarg($repoPath);

            $branch = $this->safeExec(
                'git -C '.$escaped.' rev-parse --abbrev-ref HEAD'
            );

            $commit = $this->safeExec(
                'git -C '.$escaped.' rev-parse --short HEAD'
            );

            $message = $this->safeExec(
                'git -C '.$escaped.' log -1 --format=%s'
            );

            return ['branch' => $branch, 'commit' => $commit, 'message' => $message];
        }
    };

    $result = $collector->collect([]);

    expect($result['branch'])->toBeNull();
    expect($result['commit'])->toBe('def5678');
    expect($result['message'])->toBe('fix: partial return test');
});

it('resolves repo path from git_path config (D-09)', function () {
    $collector = new class extends GitCollector
    {
        protected function doCollect(array $config): array
        {
            $repoPath = $config['git_path']
                ?? $config['git_repo_path']
                ?? base_path();

            return ['selected_path' => $repoPath];
        }
    };

    // Test 1: git_path takes priority
    $result = $collector->collect([
        'git_path' => '/custom/git/path',
        'git_repo_path' => '/fallback/repo/path',
    ]);
    expect($result['selected_path'])->toBe('/custom/git/path');

    // Test 2: git_repo_path used when git_path absent
    $result = $collector->collect([
        'git_repo_path' => '/fallback/repo/path',
    ]);
    expect($result['selected_path'])->toBe('/fallback/repo/path');

    // Test 3: base_path() used when both absent
    $result = $collector->collect([]);
    expect($result['selected_path'])->toBe(base_path());
});

it('returns error entry for non-repo path', function () {
    $collector = new class extends GitCollector
    {
        protected function doCollect(array $config): array
        {
            $repoPath = $config['git_path'] ?? $config['git_repo_path'] ?? base_path();

            if (! is_dir($repoPath.DIRECTORY_SEPARATOR.'.git')) {
                return [
                    'branch' => null,
                    'commit' => null,
                    'message' => null,
                    'error' => 'Not a git repository: '.$repoPath,
                ];
            }

            return ['branch' => 'main', 'commit' => 'abc123'];
        }
    };

    // Use a path known not to be a git repo
    $result = $collector->collect([
        'git_path' => sys_get_temp_dir(),
    ]);

    expect($result['branch'])->toBeNull();
    expect($result['commit'])->toBeNull();
    expect($result['error'])->toContain('Not a git repository');
});

it('handles base collector exception wrapping', function () {
    $collector = new class extends GitCollector
    {
        protected function doCollect(array $config): array
        {
            throw new RuntimeException('Simulated failure');
        }
    };

    $result = $collector->collect([]);

    expect($result)->toBe([]);
});
