<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

use PDO;
use Throwable;

/**
 * Verifies a database connection from installer-supplied credentials WITHOUT persisting them or
 * mutating the app's connections — a throwaway PDO handle that is opened, pinged, and discarded.
 *
 * SECURITY: the returned message is safe to show the operator — a short, human reason plus the SQLSTATE
 * class at most. It never echoes the password or the full driver exception (which can contain the DSN).
 */
final class DatabaseVerifier
{
    /** @return array{ok:bool, message:string} */
    public function verify(string $driver, string $host, int $port, string $database, string $username, string $password): array
    {
        try {
            $dsn = match ($driver) {
                'mysql', 'mariadb' => "mysql:host={$host};port={$port};dbname={$database}",
                'pgsql' => "pgsql:host={$host};port={$port};dbname={$database}",
                'sqlite' => 'sqlite:'.$database,
                default => throw new \InvalidArgumentException('Unsupported database driver.'),
            };

            $pdo = $driver === 'sqlite'
                ? new PDO($dsn)
                : new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);

            // A trivial round-trip proves real connectivity, not just a constructed handle.
            $pdo->query('SELECT 1');

            return ['ok' => true, 'message' => 'Connected successfully.'];
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (\PDOException $e) {
            // SQLSTATE is safe and useful; the full message can leak the DSN, so we do not surface it.
            $sqlstate = is_string($e->getCode()) && $e->getCode() !== '' ? " (SQLSTATE {$e->getCode()})" : '';

            return ['ok' => false, 'message' => 'Could not connect to the database'.$sqlstate.'. Check the host, port, name, and credentials.'];
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Could not connect to the database. Check the connection details.'];
        }
    }
}
