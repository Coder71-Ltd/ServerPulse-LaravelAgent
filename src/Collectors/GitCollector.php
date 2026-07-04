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
        // D-09: fallback chain for repo path
        $repoPath = $config['git_path']
            ?? $config['git_repo_path']
            ?? base_path();

        // D-10: verify it's a git repo (Pitfall 5: git hangs on non-git dirs)
        if (! is_dir($repoPath.DIRECTORY_SEPARATOR.'.git')) {
            return [
                'branch' => null,
                'commit' => null,
                'error' => 'Not a git repository: '.$repoPath,
            ]; // GIT-04
        }

        $escaped = escapeshellarg($repoPath);

        // GIT-01: branch detection
        $branch = $this->safeExec(
            'git -C '.$escaped.' rev-parse --abbrev-ref HEAD 2>/dev/null'
        );
        // Returns 'HEAD' in detached state — valid, not an error (Pitfall 3)

        // GIT-02: short commit hash
        $commit = $this->safeExec(
            'git -C '.$escaped.' rev-parse --short HEAD 2>/dev/null'
        );

        return [
            'branch' => $branch,
            'commit' => $commit,
        ];
    }
}
