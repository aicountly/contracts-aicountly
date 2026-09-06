<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Carries a field → message map to the controller, which renders it as a 422.
 */
final class ValidationFailed extends RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message);
    }
}
