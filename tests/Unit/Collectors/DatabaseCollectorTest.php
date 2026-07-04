<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use ServerPulse\Agent\Collectors\DatabaseCollector;
use ServerPulse\Agent\ServerPulseServiceProvider;

uses(TestCase::class);

beforeEach(function () {
    $this->app->register(ServerPulseServiceProvider::class);
    $this->app->boot();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Assert that the result array contains all 4 expected database metric keys.
 */
function assertDatabaseKeys(array $result): void
{
    expect($result)->toHaveKeys([
        'mysql_running',
        'slow_queries',
        'db_driver',
        'db_connections',
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('returns all database metric keys', function () {
    $collector = new DatabaseCollector;
    $result = $collector->collect([]);

    assertDatabaseKeys($result);
});

it('detects mysql running via pgrep', function () {
    $collector = new class extends DatabaseCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'pgrep -x mysqld')) {
                return '1234'; // mock PID found
            }

            return null;
        }
    };

    $result = $collector->collect([]);

    expect($result['mysql_running'])->toBeTrue();
});

it('detects mariadb via pgrep', function () {
    $collector = new class extends DatabaseCollector
    {
        protected function safeExec(string $command): ?string
        {
            if (str_contains($command, 'pgrep -x mariadbd')) {
                return '5678'; // mock PID found
            }

            return null; // mysqld: not found
        }
    };

    $result = $collector->collect([]);

    expect($result['mysql_running'])->toBeTrue();
});

it('returns null mysql_running when pgrep fails', function () {
    $collector = new class extends DatabaseCollector
    {
        protected function safeExec(string $command): ?string
        {
            return null; // all pgrep commands fail
        }
    };

    $result = $collector->collect([]);

    expect($result['mysql_running'])->toBeNull();
});

it('reports slow queries from Laravel-managed PDO', function () {
    $pdoMock = Mockery::mock(PDO::class);
    $stmtMock = Mockery::mock(PDOStatement::class);

    $pdoMock->shouldReceive('query')
        ->with("SHOW GLOBAL STATUS LIKE 'Slow_queries'")
        ->once()
        ->andReturn($stmtMock);

    $stmtMock->shouldReceive('fetch')
        ->once()
        ->andReturn((object) ['Variable_name' => 'Slow_queries', 'Value' => '42']);

    DB::shouldReceive('connection->getPdo')
        ->once()
        ->andReturn($pdoMock);

    $collector = new DatabaseCollector;
    $result = $collector->collect([]);

    expect($result['slow_queries'])->toBe(42);
});

it('returns 0 slow queries when value is 0', function () {
    $pdoMock = Mockery::mock(PDO::class);
    $stmtMock = Mockery::mock(PDOStatement::class);

    $pdoMock->shouldReceive('query')
        ->with("SHOW GLOBAL STATUS LIKE 'Slow_queries'")
        ->once()
        ->andReturn($stmtMock);

    $stmtMock->shouldReceive('fetch')
        ->once()
        ->andReturn((object) ['Variable_name' => 'Slow_queries', 'Value' => '0']);

    DB::shouldReceive('connection->getPdo')
        ->once()
        ->andReturn($pdoMock);

    $collector = new DatabaseCollector;
    $result = $collector->collect([]);

    expect($result['slow_queries'])->toBe(0);
});

it('returns null slow_queries when laravel PDO fails', function () {
    DB::shouldReceive('connection->getPdo')
        ->once()
        ->andThrow(new RuntimeException('Database connection failed'));

    // Ensure no credentials in config so raw PDO fallback is also skipped (DB-04)
    Config::set('database.default', 'testing');
    Config::set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    $collector = new DatabaseCollector;
    $result = $collector->collect([]);

    // slow_queries is null because PDO failed
    expect($result['slow_queries'])->toBeNull();

    // mysql_running should still work (metric independence)
    expect($result)->toHaveKey('mysql_running');

    // db_driver should be set from config
    expect($result['db_driver'])->toBe('testing');
});

it('returns null slow_queries when SHOW GLOBAL STATUS fails (PROCESS privilege)', function () {
    $pdoMock = Mockery::mock(PDO::class);

    $pdoMock->shouldReceive('query')
        ->with("SHOW GLOBAL STATUS LIKE 'Slow_queries'")
        ->once()
        ->andThrow(new PDOException('PROCESS privilege not granted'));

    DB::shouldReceive('connection->getPdo')
        ->once()
        ->andReturn($pdoMock);

    $collector = new DatabaseCollector;
    $result = $collector->collect([]);

    // slow_queries is null because query failed
    expect($result['slow_queries'])->toBeNull();

    // mysql_running should still be in result (metric independence)
    expect($result)->toHaveKey('mysql_running');

    // db_driver should be set
    expect($result)->toHaveKey('db_driver');
});

it('reports db_driver from config', function () {
    Config::set('database.default', 'pgsql');

    $collector = new DatabaseCollector;
    $result = $collector->collect([]);

    expect($result['db_driver'])->toBe('pgsql');
});

it('reports all db connections with drivers (D-08)', function () {
    Config::set('database.connections', [
        'mysql' => ['driver' => 'mysql', 'host' => 'localhost'],
        'pgsql' => ['driver' => 'pgsql', 'host' => 'pg.example.com'],
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    $collector = new DatabaseCollector;
    $result = $collector->collect([]);

    expect($result['db_connections'])->toBeArray();
    expect($result['db_connections'])->toHaveCount(3);

    expect($result['db_connections'][0]['name'])->toBe('mysql');
    expect($result['db_connections'][0]['driver'])->toBe('mysql');

    expect($result['db_connections'][1]['name'])->toBe('pgsql');
    expect($result['db_connections'][1]['driver'])->toBe('pgsql');

    expect($result['db_connections'][2]['name'])->toBe('sqlite');
    expect($result['db_connections'][2]['driver'])->toBe('sqlite');
});

it('handles missing db connections config gracefully', function () {
    Config::set('database.connections', []);

    $collector = new DatabaseCollector;
    $result = $collector->collect([]);

    expect($result['db_connections'])->toBe([]);
});

it('handles base collector exception wrapping', function () {
    $collector = new class extends DatabaseCollector
    {
        protected function doCollect(array $config): array
        {
            throw new RuntimeException('Simulated failure');
        }
    };

    $result = $collector->collect([]);

    expect($result)->toBe([]);
});
