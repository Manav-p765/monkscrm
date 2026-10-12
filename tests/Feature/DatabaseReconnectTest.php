<?php

namespace Tests\Feature;

use App\Support\LostConnectionDetector;
use Illuminate\Contracts\Database\LostConnectionDetector as LostConnectionDetectorContract;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Neon suspends an idle compute (scale to zero) and may recycle connections. Laravel must notice the dead
 * connection and reconnect, retrying the query once, but never replay part of a transaction.
 */
class DatabaseReconnectTest extends TestCase
{
    private const NEON_ERRORS = [
        'SQLSTATE[57P01]: Admin shutdown: 7 FATAL:  terminating connection due to administrator command',
        'SQLSTATE[08006] [7] SSL SYSCALL error: EOF detected',
        'SQLSTATE[HY000]: General error: 7 SSL SYSCALL error: EOF detected',
        'SQLSTATE[08006]: could not receive data from server: Connection reset',
        'SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly',
    ];

    /** A fresh connection (outside the test's own transaction) whose PDO fails once with $message. */
    private function connectionThatDropsOnce(string $message): Connection
    {
        config(['database.connections.probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('probe');
        $connection = DB::connection('probe');
        $real = $connection->getPdo();

        $dead = Mockery::mock(PDO::class);
        $dead->shouldReceive('prepare')->andThrow(new PDOException($message));
        $dead->shouldReceive('exec')->andReturn(0); // BEGIN (SQLite opens transactions with exec)
        $dead->shouldReceive('inTransaction')->andReturn(true);
        $dead->shouldReceive('rollBack')->andReturn(true);
        $connection->setPdo($dead);
        $connection->setReconnector(fn (Connection $c) => $c->setPdo($real));

        return $connection;
    }

    public function test_neon_shutdown_messages_count_as_lost_connections(): void
    {
        $detector = app(LostConnectionDetectorContract::class);
        $this->assertInstanceOf(LostConnectionDetector::class, $detector);

        foreach (self::NEON_ERRORS as $message) {
            $this->assertTrue($detector->causedByLostConnection(new PDOException($message)), $message);
        }
        $this->assertFalse($detector->causedByLostConnection(new PDOException('SQLSTATE[23505]: Unique violation')));
    }

    public function test_a_query_outside_a_transaction_reconnects_and_succeeds(): void
    {
        foreach (self::NEON_ERRORS as $message) {
            $connection = $this->connectionThatDropsOnce($message);

            $this->assertSame(1, (int) $connection->selectOne('select 1 as one')->one, $message);
        }
    }

    public function test_a_query_inside_a_transaction_is_not_retried(): void
    {
        $connection = $this->connectionThatDropsOnce(self::NEON_ERRORS[0]);
        $connection->getPdo(); // keep the dead PDO for the transaction
        $connection->setReconnector(fn () => $this->fail('Must not reconnect in the middle of a transaction.'));

        $this->expectException(QueryException::class);
        $connection->beginTransaction();
        $connection->selectOne('select 1 as one');
    }

    public function test_pgsql_uses_no_persistent_connections(): void
    {
        $options = config('database.connections.pgsql.options', []);

        $this->assertEmpty($options[PDO::ATTR_PERSISTENT] ?? null, 'persistent PDO connections would outlive Neon idle shutdowns');
    }
}
