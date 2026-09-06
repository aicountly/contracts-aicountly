<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read-side of one HTTP request.
 *
 * Everything is a getter over the superglobals so a test can seed `$_GET` /
 * `$_SERVER` and exercise a controller without a web server.
 */
final class Request
{
    private static ?array $jsonCache = null;

    private static ?string $rawBodyOverride = null;

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** @return array<string,mixed> */
    public static function jsonBody(): array
    {
        if (self::$jsonCache !== null) {
            return self::$jsonCache;
        }

        $raw = self::$rawBodyOverride ?? (file_get_contents('php://input') ?: '');
        if ($raw === '') {
            return self::$jsonCache = [];
        }

        $decoded = json_decode($raw, true);

        return self::$jsonCache = is_array($decoded) ? $decoded : [];
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        if (! isset($_GET[$key])) {
            return $default;
        }
        $v = $_GET[$key];

        return is_string($v) || is_numeric($v) ? (string) $v : $default;
    }

    /**
     * A repeated query parameter, in either `?tag=a&tag=b` or `?tag=a,b` form.
     *
     * @return list<string>
     */
    public static function queryList(string $key): array
    {
        if (! isset($_GET[$key])) {
            return [];
        }

        $raw = $_GET[$key];
        if (is_array($raw)) {
            $flat = [];
            foreach ($raw as $v) {
                if (is_string($v) || is_numeric($v)) {
                    $flat[] = (string) $v;
                }
            }

            return array_values(array_filter($flat, static fn (string $v): bool => trim($v) !== ''));
        }

        if (! is_string($raw) && ! is_numeric($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', (string) $raw)),
            static fn (string $v): bool => $v !== ''
        ));
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        // After an internal rewrite Apache exposes the Authorization header
        // only under the REDIRECT_ prefix; a deployment that reads one form and
        // not the other answers 401 to every sign-in with nothing in the log.
        $redirect = 'REDIRECT_' . $key;
        if (isset($_SERVER[$redirect]) && is_string($_SERVER[$redirect])) {
            return $_SERVER[$redirect];
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $h => $val) {
                    if (strcasecmp((string) $h, $name) === 0 && is_string($val)) {
                        return $val;
                    }
                }
            }
        }

        return null;
    }

    public static function bearerToken(): string
    {
        $header = self::header('Authorization') ?? '';
        if ($header === '' || preg_match('/Bearer\s+(.+)/i', $header, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }

    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($remote) ? $remote : '';
    }

    public static function userAgent(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return is_string($ua) ? substr($ua, 0, 255) : '';
    }

    /**
     * Page and page size, clamped.
     *
     * The clamp is a denial-of-service control, not a nicety: `per_page` is
     * caller-supplied and reaches a LIMIT, so an unbounded value is a way to
     * ask the database for the entire tenant in one request.
     *
     * @return array{page: int, per_page: int, offset: int}
     */
    public static function pagination(int $defaultPerPage = 25, int $maxPerPage = 100): array
    {
        $page = (int) (self::query('page') ?? '1');
        if ($page < 1) {
            $page = 1;
        }
        if ($page > 100000) {
            $page = 100000;
        }

        $perPage = (int) (self::query('per_page') ?? (string) $defaultPerPage);
        if ($perPage < 1) {
            $perPage = $defaultPerPage;
        }
        if ($perPage > $maxPerPage) {
            $perPage = $maxPerPage;
        }

        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }

    /** @internal tests only @param array<string,mixed>|string|null $body */
    public static function configureForTests(array|string|null $body = null): void
    {
        self::$jsonCache       = null;
        self::$rawBodyOverride = null;

        if (is_array($body)) {
            self::$jsonCache = $body;
        } elseif (is_string($body)) {
            self::$rawBodyOverride = $body;
        }
    }
}
