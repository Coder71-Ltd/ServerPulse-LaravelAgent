<?php

namespace ServerPulse\Agent\Collectors;

use ServerPulse\Agent\Support\ExecutesShellCommands;

class GitCollector extends BaseCollector
{
    use ExecutesShellCommands;

    public function key(): string
    {
        return 'git';
    }

    /**
     * Collect Git repository branch and commit hash.
     *
     * GIT-01: Current branch name via git rev-parse --abbrev-ref HEAD.
     * GIT-02: Short commit hash via git rev-parse --short HEAD.
     * GIT-03: Path resolution via config override -> base_path().
     * GIT-04: Graceful error for non-repo paths.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function doCollect(array $config): array
    {
        $repoPath = $this->resolveRepoPath($config);

        if (! is_dir($repoPath.DIRECTORY_SEPARATOR.'.git')) {
            return [
                'branch' => null,
                'commit' => null,
                'message' => null,
                'error' => 'Not a git repository: '.$repoPath,
            ];
        }

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

        return [
            'branch' => $branch,
            'commit' => $commit,
            'message' => $message,
        ];
    }

    /**
     * Resolve the local filesystem path to a git repository.
     *
     * Handles multiple config key formats:
     *   - git_path (string): "/var/www/myapp"
     *   - git_repo_path (string): "/var/www/myapp"
     *   - git_paths (array): [{"path": "/var/www/myapp", "label": "..."}]
     *   - git_paths (array): ["/var/www/myapp"]
     *
     * Falls back to base_path() when nothing is configured.
     */
    private function resolveRepoPath(array $config): string
    {
        if (isset($config['git_path']) && is_string($config['git_path'])) {
            return $config['git_path'];
        }

        if (isset($config['git_repo_path']) && is_string($config['git_repo_path'])) {
            return $config['git_repo_path'];
        }

        if (isset($config['git_paths']) && is_array($config['git_paths']) && $config['git_paths'] !== []) {
            $first = $config['git_paths'][0];

            if (is_string($first)) {
                return $first;
            }

            if (is_array($first) && isset($first['path']) && is_string($first['path'])) {
                return $first['path'];
            }
        }

        return base_path();
    }
}
