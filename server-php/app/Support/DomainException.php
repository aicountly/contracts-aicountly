<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * A business rule refused the operation.
 *
 * Distinct from ValidationFailed (the input was malformed) and from a bare
 * RuntimeException (something broke). This one means the input was fine and
 * the answer is still no — "an executed contract cannot be edited", "this
 * contract already has an open approval". The controller maps it to a 409 by
 * default, or to whatever $status the factory below chose.
 *
 * `errorCode` rather than `code`: Exception::$code already exists as a
 * non-readonly int, and PHP refuses to redeclare it as a readonly string.
 */
final class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'OPERATION_NOT_ALLOWED',
        public readonly int $status = 409
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self($message, 'NOT_FOUND', 404);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 'PERMISSION_DENIED', 403);
    }

    public static function badRequest(string $message, string $errorCode = 'BAD_REQUEST'): self
    {
        return new self($message, $errorCode, 400);
    }

    public static function conflict(string $message, string $errorCode = 'CONFLICT'): self
    {
        return new self($message, $errorCode, 409);
    }

    public static function unavailable(string $message, string $errorCode = 'INTEGRATION_UNAVAILABLE'): self
    {
        return new self($message, $errorCode, 503);
    }
}
