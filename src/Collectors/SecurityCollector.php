<?php

namespace ServerPulse\Agent\Collectors;

use ServerPulse\Agent\Support\ExecutesShellCommands;

class SecurityCollector extends BaseCollector
{
    use ExecutesShellCommands;

    /**
     * The collector key used in the report payload.
     */
    public function key(): string
    {
        return 'security';
    }

    /**
     * Collect failed SSH login attempt count for the current hour.
     *
     * Builds a syslog-format timestamp prefix via `date +'%b %e %H:'`, then
     * greps system auth logs for "Failed password" entries matching the prefix.
     *
     * Dual-path check (D-07):
     *   - /var/log/auth.log (Debian/Ubuntu)
     *   - /var/log/secure   (RHEL/CentOS)
     *
     * D-09: Missing, unreadable, or unrecognized-format auth logs return
     * count 0 — never crash the cycle.
     *
     * D-08: Returns integer count only — no log entry contents.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function doCollect(array $config): array
    {
        // Get current-hour syslog timestamp prefix (e.g. "Jul  4 10:")
        $nowPrefix = $this->safeExec("date +'%b %e %H:' 2>/dev/null");

        // D-09: If shell is unavailable, graceful degradation
        if ($nowPrefix === null) {
            return ['failed_ssh_1h' => 0];
        }

        // D-07: Dual-path auth log check
        $authPaths = ['/var/log/auth.log', '/var/log/secure'];
        $count = 0;

        foreach ($authPaths as $authPath) {
            // D-09: Skip unreadable/missing files
            if (! is_readable($authPath)) {
                continue;
            }

            $result = $this->countFailedSshInHour($authPath, $nowPrefix);

            if ($result !== null) {
                $count += $result;
            }
        }

        // D-08: Integer count only — no entry contents
        return ['failed_ssh_1h' => $count];
    }

    /**
     * Count "Failed password" entries in an auth log file matching the
     * given syslog timestamp prefix.
     *
     * T-04-03: Uses escapeshellarg() on the file path for defense-in-depth.
     *
     * @param  string  $path  Absolute path to an auth log file.
     * @param  string  $prefix  Syslog timestamp prefix (e.g. "Jul  4 10:").
     * @return int|null Integer count, or null if the command failed.
     */
    private function countFailedSshInHour(string $path, string $prefix): ?int
    {
        $cmd = 'grep "'.$prefix.'" '.escapeshellarg($path).' 2>/dev/null | grep -c "Failed password" 2>/dev/null';

        $result = $this->safeExec($cmd);

        // D-09: Non-numeric or empty results treated as null (graceful)
        if ($result === null || $result === '' || ! is_numeric($result)) {
            return null;
        }

        return (int) $result;
    }
}
