<?php

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Two processes write the same SQLite file: the web container (subscriptions,
 * admin) and the consumer (queue, scheduler). WAL lets readers go on while one
 * writes, and a busy timeout makes the second writer wait its turn instead of
 * failing at once with "database is locked".
 */
final class SqliteMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                if (\in_array($params['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true)) {
                    $connection->exec('PRAGMA journal_mode = WAL');
                    $connection->exec('PRAGMA busy_timeout = 5000');
                    $connection->exec('PRAGMA foreign_keys = ON');
                }

                return $connection;
            }
        };
    }
}
