<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The one JSON envelope every Contracts endpoint answers in.
 *
 * Shape matches the rest of the AICOUNTLY fleet (see Drive's
 * `SesAuthController::jsonSuccess`/`jsonError`) so a shared client can read any
 * product's reply:
 *
 *   success  {"success":true,"data":…,"errors":[]}
 *   failure  {"success":false,"message":…,"error":"CODE","data":null,"errors":{…}}
 *
 * `errors` is a map of field → message for validation failures and
 * `{CODE: message}` otherwise, so a form can highlight fields without the
 * caller having to know which kind of failure it got.
 */
final class Response
{
    /** Set by tests so a controller can be exercised without exiting the process. */
    private static bool $testMode = false;

    /** @var array{status: int, body: array<string,mixed>}|null */
    private static ?array $lastForTests = null;

    public static function success(mixed $data = null, int $status = 200, array $meta = []): never
    {
        $payload = ['success' => true, 'data' => $data, 'errors' => []];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        self::send($status, $payload);
    }

    /** @param array<string,mixed> $errors */
    public static function error(string $code, string $message, int $status, array $errors = []): never
    {
        self::send($status, [
            'success' => false,
            'message' => $message,
            'error'   => $code,
            'data'    => null,
            'errors'  => $errors !== [] ? $errors : [$code => $message],
        ]);
    }

    /** @param array<string,string> $fieldErrors */
    public static function validationError(array $fieldErrors, string $message = 'Please correct the highlighted fields.'): never
    {
        self::error('VALIDATION_FAILED', $message, 422, $fieldErrors);
    }

    public static function notFound(string $message = 'Not found.'): never
    {
        self::error('NOT_FOUND', $message, 404);
    }

    /**
     * Deliberately identical to notFound() for tenant-scope misses.
     *
     * A caller reaching for another company's contract must not be able to tell
     * "exists but forbidden" from "does not exist" — that difference is an
     * enumeration oracle over every contract id in the system.
     */
    public static function forbidden(string $message = 'You do not have permission to do that.'): never
    {
        self::error('PERMISSION_DENIED', $message, 403);
    }

    public static function unauthorized(string $message = 'Invalid or expired session.'): never
    {
        self::error('UNAUTHORIZED', $message, 401);
    }

    public static function conflict(string $message): never
    {
        self::error('CONFLICT', $message, 409);
    }

    /** @param array<string,mixed> $body */
    public static function send(int $status, array $body): never
    {
        if (self::$testMode) {
            self::$lastForTests = ['status' => $status, 'body' => $body];

            throw new ResponseSent($status);
        }

        if (! headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * A page of rows plus the counts a table needs to render its pager.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function paginated(array $rows, int $total, int $page, int $perPage, array $extra = []): never
    {
        self::success(array_merge([
            'items' => $rows,
            'total' => $total,
            'page'  => $page,
            'per_page' => $perPage,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ], $extra));
    }

    /** @internal tests only */
    public static function enableTestMode(): void
    {
        self::$testMode     = true;
        self::$lastForTests = null;
    }

    /** @internal tests only @return array{status: int, body: array<string,mixed>}|null */
    public static function lastForTests(): ?array
    {
        return self::$lastForTests;
    }
}

/** Thrown instead of exit() when Response is in test mode. */
final class ResponseSent extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Response sent with status ' . $status);
    }
}
