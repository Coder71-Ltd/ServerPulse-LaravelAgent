<?php

namespace ServerPulse\Agent\Collectors;

use Illuminate\Support\Facades\DB;
use ServerPulse\Agent\Support\ExecutesShellCommands;

class DatabaseCollector extends BaseCollector
{
    use ExecutesShellCommands;

    public function key(): string
    {
        return 'database';
    }

    /**
     * Collect database metrics: MySQL/MariaDB running status, slow query count,
     * and Laravel DB connection metadata.
     *
     * Metric independence is guaranteed by ordering:
     * config-based (db_driver, db_connections) and shell-based (mysql_running)
     * are collected BEFORE PDO-based (slow_queries). A PDO failure never
     * affects the already-collected config/shell metrics.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function doCollect(array $config): array
    {
        // Config-based metrics (no try/catch needed — pure array reads)
        $dbDriver = config('database.default');
        $dbConnections = $this->getDbConnections();

        // Shell-based metric (mysql_running via pgrep, DB-01)
        $mysqlRunning = $this->detectMysqlRunning();

        // PDO-based metric (slow_queries — isolated try/catch per Pitfall 2)
        $slowQueries = $this->getSlowQueries();

        return [
            'mysql_running' => $mysqlRunning,
            'slow_queries' => $slowQueries,
            'db_driver' => $dbDriver,
            'db_connections' => $dbConnections,
        ];
    }

    /**
     * DB-01: Detect MySQL/MariaDB running status via pgrep.
     *
     * Checks for mysqld first, then mariadbd on systems where MariaDB
     * uses a different binary name.
     *
     * @return bool|null True if a MySQL/MariaDB process is found, null if
     *                   pgrep is unavailable or no process is detected.
     */
    private function detectMysqlRunning(): ?bool
    {
        $output = $this->safeExec('pgrep -x mysqld 2>/dev/null');

        if ($output !== null && trim($output) !== '') {
            return true;
        }

        // MariaDB uses mysqld on some distros, mariadbd on others
        $mariadb = $this->safeExec('pgrep -x mariadbd 2>/dev/null');

        if ($mariadb !== null && trim($mariadb) !== '') {
            return true;
        }

        return null;
    }

    /**
     * DB-03, D-08: Report ALL configured Laravel DB connections with drivers.
     *
     * Returns an array of {name, driver} entries, one per connection in
     * config('database.connections'). Includes all configured drivers
     * (mysql, pgsql, sqlite, sqlsrv), not just MySQL.
     *
     * @return array<int, array{name: string, driver: string}>
     */
    private function getDbConnections(): array
    {
        $connections = config('database.connections', []);
        $result = [];

        foreach ($connections as $name => $connConfig) {
            $result[] = [
                'name' => $name,
                'driver' => $connConfig['driver'] ?? 'unknown',
            ];
        }

        return $result;
    }

    /**
     * D-04: Obtain a PDO connection for SHOW GLOBAL STATUS queries.
     *
     * Primary path: Laravel-managed PDO via DB::connection()->getPdo().
     * Fallback path: Raw PDO constructed from config credentials.
     *
     * DB-04: If the default connection config lacks username or password,
     * PDO is skipped entirely without error.
     *
     * @return \PDO|null PDO instance or null if unavailable
     */
    private function getPdoForSlowQueries(): ?\PDO
    {
        // D-04 primary: Laravel-managed PDO
        try {
            return DB::connection()->getPdo();
        } catch (\Throwable $e) {
            // Fall through to raw PDO from config
        }

        // D-04 fallback: raw PDO from config
        $default = config('database.default');
        $conn = config('database.connections.'.$default);

        if (! is_array($conn)) {
            return null;
        }

        // DB-04: Skip if no credentials
        if (empty($conn['username']) || empty($conn['password'])) {
            return null;
        }

        try {
            return new \PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s',
                    $conn['host'] ?? '127.0.0.1',
                    $conn['port'] ?? 3306,
                    $conn['database'] ?? ''
                ),
                $conn['username'],
                $conn['password'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 3,
                ]
            );
        } catch (\Throwable $e) {
            return null; // DB-05: silent failure
        }
    }

    /**
     * DB-02: Query SHOW GLOBAL STATUS for slow query count.
     *
     * D-07: Uses the default connection only (SHOW GLOBAL STATUS is
     * server-wide, not per-connection). D-06: Returns the cumulative
     * value from the server — the backend computes deltas.
     *
     * Isolated try/catch per PITFALLS.md Pitfall 2: SHOW GLOBAL STATUS
     * requires PROCESS privilege. If the DB user lacks this privilege,
     * null is returned without affecting other metrics.
     *
     * @return int|null The cumulative slow query count, or null on failure
     */
    private function getSlowQueries(): ?int
    {
        $pdo = $this->getPdoForSlowQueries();

        if ($pdo === null) {
            return null;
        }

        try {
            $stmt = $pdo->query("SHOW GLOBAL STATUS LIKE 'Slow_queries'");

            if ($stmt === false) {
                return null;
            }

            $row = $stmt->fetch(\PDO::FETCH_OBJ);

            return isset($row->Value) ? (int) $row->Value : 0;
        } catch (\Throwable $e) {
            return null; // PROCESS privilege missing — silently skip
        }
    }
}
