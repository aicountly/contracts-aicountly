<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Runtime configuration from `api/.env`.
 *
 * The deploy never uploads a `.env` (every rsync excludes it), so this file is
 * created once on the server and read on every request. Values from the file
 * win over an inherited `getenv()` — on cPanel the shell environment is stale
 * far more often than the file is.
 */
final class Env
{
    private static bool $loaded = false;

    private static ?string $loadedFrom = null;

    private static ?string $apiRoot = null;

    /** Directory containing app/, database/ and .env. Set by the front controller. */
    public static function setApiRoot(string $path): void
    {
        self::$apiRoot = rtrim($path, '/\\');
    }

    public static function apiRoot(): string
    {
        if (is_string(self::$apiRoot) && self::$apiRoot !== '') {
            return self::$apiRoot;
        }

        return dirname(__DIR__, 2);
    }

    public static function loadedFrom(): ?string
    {
        self::bootstrap();

        return self::$loadedFrom;
    }

    public static function get(string $key, string $default = ''): string
    {
        self::bootstrap();

        if (array_key_exists($key, $_ENV) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }

        $fromGetenv = getenv($key);
        if (is_string($fromGetenv) && $fromGetenv !== '') {
            return $fromGetenv;
        }

        return $default;
    }

    public static function int(string $key, int $default): int
    {
        $raw = trim(self::get($key));

        return $raw === '' || ! preg_match('/^-?\d+$/', $raw) ? $default : (int) $raw;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $raw = strtolower(trim(self::get($key)));
        if ($raw === '') {
            return $default;
        }

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        $raw = trim(self::get($key));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Value of an env var, unquoted.
     *
     * Concatenation (not interpolation) when handing this to putenv() is
     * deliberate — a `$` inside a database password is otherwise expanded away.
     */
    public static function parseValue(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $first = $raw[0];
        $last  = $raw[strlen($raw) - 1];
        if (strlen($raw) >= 2 && (($first === '"' && $last === '"') || ($first === "'" && $last === "'"))) {
            return substr($raw, 1, -1);
        }

        return $raw;
    }

    /** @param array<string,string> $vars @internal tests only */
    public static function configureForTests(array $vars): void
    {
        self::$loaded     = true;
        self::$loadedFrom = null;
        foreach ($vars as $k => $v) {
            $_ENV[$k]    = $v;
            $_SERVER[$k] = $v;
            putenv($k . '=' . $v);
        }
    }

    /** @internal tests only */
    public static function reset(): void
    {
        self::$loaded     = false;
        self::$loadedFrom = null;
    }

    private static function bootstrap(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        // Every entry point (front controller, cron script, test) reaches Env
        // before doing anything date-related, and this is the one place that
        // reliably runs first. php.ini's date.timezone is unset on these hosts,
        // so without pinning it here PHP-computed timestamps would drift away
        // from the UTC session timezone forced on the Postgres connection.
        date_default_timezone_set('UTC');

        foreach (self::candidates() as $candidate) {
            if (! is_readable($candidate)) {
                continue;
            }
            $lines = file($candidate, FILE_IGNORE_NEW_LINES);
            if (! is_array($lines)) {
                continue;
            }
            self::$loadedFrom = $candidate;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                if ($k === '') {
                    continue;
                }
                $v = self::parseValue($v);
                putenv($k . '=' . $v);
                $_ENV[$k]    = $v;
                $_SERVER[$k] = $v;
            }
            break;
        }
    }

    /** @return list<string> */
    private static function candidates(): array
    {
        $root = self::apiRoot();
        $doc  = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])
            ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\')
            : '';

        $out = [
            $root . DIRECTORY_SEPARATOR . '.env',
            dirname($root) . DIRECTORY_SEPARATOR . '.env',
        ];
        if ($doc !== '') {
            $out[] = $doc . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . '.env';
            $out[] = $doc . DIRECTORY_SEPARATOR . '.env';
        }

        return array_values(array_unique($out));
    }
}
