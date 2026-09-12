<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * The single PostgreSQL connection for this product.
 *
 * Timestamps are the reason this is not just `new PDO(...)` at the call site:
 * every table here uses bare `TIMESTAMP` with `DEFAULT CURRENT_TIMESTAMP`, so
 * without pinning the session timezone the server's own zone leaks into every
 * stored date — and contract expiry, notice deadlines and obligation due dates
 * are the whole product. UTC everywhere, converted for display only.
 */
final class Database
{
    private static ?PDO $pdo = null;

    private static ?string $lastError = null;

    public static function pdo(): ?PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        self::$lastError = null;

        if (! in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            self::$lastError = 'PHP PDO pgsql driver is not installed';

            return null;
        }

        $params = self::connectionParams();
        if ($params['user'] === '') {
            self::$lastError = 'DB_USER is not set in api/.env';

            return null;
        }
        if ($params['name'] === '') {
            self::$lastError = 'DB_NAME is not set in api/.env';

            return null;
        }

        try {
            self::$pdo = new PDO($params['dsn'], $params['user'], Env::get('DB_PASS'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$pdo->exec("SET TIME ZONE 'UTC'");
        } catch (Throwable $e) {
            self::$pdo       = null;
            self::$lastError = $e->getMessage();
        }

        return self::$pdo;
    }

    public static function isAvailable(): bool
    {
        return self::pdo() !== null;
    }

    public static function lastError(): ?string
    {
        return self::$pdo !== null ? null : self::$lastError;
    }

    /** Operator-facing reason the database is unreachable. Never includes credentials. */
    public static function unavailableMessage(): string
    {
        if (! in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            return 'PHP PDO pgsql extension is not enabled on this server (install php-pgsql).';
        }

        if (Env::loadedFrom() === null) {
            return 'api/.env was not found. Create ' . Env::apiRoot() . DIRECTORY_SEPARATOR . '.env'
                . ' with DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS (the deploy never uploads .env).';
        }

        $hint = self::lastError();
        if (is_string($hint) && $hint !== '') {
            return 'Database connection failed: ' . $hint;
        }

        return 'Database is not configured. Check DB_* in api/.env and PostgreSQL on the server.';
    }

    /** @return array{db_host_set: bool, db_name_set: bool, db_user_set: bool, db_pass_set: bool, pdo_pgsql: bool, env_file: string|null} */
    public static function diagnostics(): array
    {
        return [
            'env_file'    => Env::loadedFrom(),
            'db_host_set' => Env::get('DB_HOST') !== '' || Env::get('DB_SOCKET') !== '',
            'db_name_set' => Env::get('DB_NAME') !== '',
            'db_user_set' => Env::get('DB_USER') !== '',
            'db_pass_set' => Env::get('DB_PASS') !== '',
            'pdo_pgsql'   => in_array('pgsql', PDO::getAvailableDrivers(), true),
        ];
    }

    /** @return array{dsn: string, host: string, port: string, name: string, user: string} */
    public static function connectionParams(): array
    {
        $socket = Env::get('DB_SOCKET');
        $host   = Env::get('DB_HOST', '127.0.0.1');
        $port   = Env::get('DB_PORT', '5432');
        $name   = Env::get('DB_NAME');
        $user   = Env::get('DB_USER');

        if ($socket !== '' && $name !== '') {
            return [
                'dsn'  => self::withSslMode(sprintf('pgsql:host=%s;dbname=%s', $socket, $name)),
                'host' => $socket,
                'port' => '',
                'name' => $name,
                'user' => $user,
            ];
        }

        return [
            'dsn'  => self::withSslMode(sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $host !== '' ? $host : '127.0.0.1',
                $port !== '' ? $port : '5432',
                $name
            )),
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
        ];
    }

    /** @internal tests only */
    public static function configureForTests(?PDO $pdo): void
    {
        self::$pdo       = $pdo;
        self::$lastError = null;
    }

    /**
     * Run $fn inside a transaction, committing on return and rolling back on throw.
     *
     * Nested calls join the outer transaction rather than opening a second one —
     * PDO has no savepoint support here, and a nested begin would throw.
     *
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public static function transaction(PDO $pdo, callable $fn): mixed
    {
        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Split a migration file into executable statements.
     *
     * Only a semicolon at end of line ends a statement, and `$$ ... $$` bodies
     * are passed through whole, so a PL/pgSQL function containing semicolons
     * survives intact.
     *
     * @return list<string>
     */
    public static function splitSqlStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';

        $len           = strlen($sql);
        $out           = [];
        $buf           = '';
        $inDollarQuote = false;

        for ($i = 0; $i < $len; $i++) {
            if (! $inDollarQuote && $i + 1 < $len && $sql[$i] === '$' && $sql[$i + 1] === '$') {
                $inDollarQuote = true;
                $buf .= '$$';
                $i++;
                continue;
            }
            if ($inDollarQuote) {
                if ($i + 1 < $len && $sql[$i] === '$' && $sql[$i + 1] === '$') {
                    $buf .= '$$';
                    $inDollarQuote = false;
                    $i++;
                    continue;
                }
                $buf .= $sql[$i];
                continue;
            }
            if ($sql[$i] === ';') {
                $rest = substr($sql, $i + 1);
                if ($rest === '' || preg_match('/^\s*(?=\R|$)/', $rest) === 1) {
                    $t = trim($buf);
                    if ($t !== '') {
                        $out[] = $t;
                    }
                    $buf = '';
                    if (preg_match('/^\s*\R/', $rest, $m)) {
                        $i += strlen($m[0]);
                    }
                    continue;
                }
            }
            $buf .= $sql[$i];
        }

        $t = trim($buf);
        if ($t !== '') {
            $out[] = $t;
        }

        return $out;
    }

    private static function withSslMode(string $dsn): string
    {
        $ssl = Env::get('DB_SSLMODE');
        if ($ssl === '' || str_contains($dsn, 'sslmode=')) {
            return $dsn;
        }

        return $dsn . ';sslmode=' . $ssl;
    }
}
