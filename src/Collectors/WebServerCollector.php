<?php

namespace ServerPulse\Agent\Collectors;

use ServerPulse\Agent\Support\ExecutesShellCommands;

class WebServerCollector extends BaseCollector
{
    use ExecutesShellCommands;

    public function key(): string
    {
        return 'web';
    }

    /**
     * Collect web server metrics via pgrep and ss.
     *
     * Web server detection follows D-03 exclusive priority:
     *   pgrep -x nginx → pgrep -x apache2 → pgrep -x httpd (RHEL) → null
     *
     * Active TCP connections follow D-02 fallback chain:
     *   ss -t state established → parse ss -s summary → null
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function doCollect(array $config): array
    {
        // D-03: exclusive priority — nginx → apache2 → httpd (RHEL) → null
        $nginx = $this->safeExec('pgrep -x nginx 2>/dev/null');
        if ($nginx !== null) {
            $serverType = 'nginx';
            $running = true;
        } else {
            $apache = $this->safeExec('pgrep -x apache2 2>/dev/null');
            if ($apache !== null) {
                $serverType = 'apache';
                $running = true;
            } else {
                $httpd = $this->safeExec('pgrep -x httpd 2>/dev/null');
                if ($httpd !== null) {
                    $serverType = 'apache';
                    $running = true;
                } else {
                    $serverType = null;
                    $running = null;
                }
            }
        }

        // D-02: Active TCP connections with fallback chain
        $activeConnections = $this->getActiveConnections();

        return [
            'server_type' => $serverType,
            'running' => $running,
            'active_connections' => $activeConnections,
        ];
    }

    /**
     * Active TCP connections with fallback chain (D-02).
     *
     * Attempt 1: ss -t state established — count lines minus header.
     * Attempt 2: ss -s summary — extract "estab N" via regex.
     * Final: null if neither method works.
     *
     * Per D-01: only ESTABLISHED sockets are counted.
     */
    private function getActiveConnections(): ?int
    {
        // Attempt 1: ss -t state established (direct count, subtract header)
        $output = $this->safeExec('ss -t state established 2>/dev/null');
        if ($output !== null) {
            $lines = array_filter(explode("\n", $output));
            $count = count($lines) - 1; // subtract header line
            if ($count >= 0) {
                return $count;
            }
        }

        // Attempt 2: parse ss -s summary for "estab N"
        $summary = $this->safeExec('ss -s 2>/dev/null');
        if ($summary !== null && preg_match('/estab\s+(\d+)/i', $summary, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
