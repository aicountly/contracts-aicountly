<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Env;

/**
 * Which deployment is answering: `production` or `sandbox`.
 *
 * Every tenant-scoped table carries this alongside `cmp_id`. That looks
 * redundant when each environment has its own database — and it is, until the
 * day a production dump is restored into sandbox to reproduce a bug, at which
 * point the column is the only thing keeping sandbox activity out of a
 * production report.
 */
final class Environment
{
    public const PRODUCTION = 'production';
    public const SANDBOX    = 'sandbox';

    public static function resolve(): string
    {
        $configured = strtolower(trim(Env::get('APP_ENV')));
        if ($configured === self::PRODUCTION || $configured === self::SANDBOX) {
            return $configured;
        }
        if ($configured === 'local') {
            return self::SANDBOX;
        }

        return self::fromHost();
    }

    public static function fromHost(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';

        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
            return self::SANDBOX;
        }

        if (str_ends_with($host, '.gh.aicountly.com') || str_starts_with($host, 'gh-')) {
            return self::SANDBOX;
        }

        return self::PRODUCTION;
    }

    /**
     * Refuse to serve when APP_ENV and the hostname disagree.
     *
     * A sandbox `.env` copied onto the production host is the accident this
     * guards: it would have production traffic writing rows tagged `sandbox`,
     * which no report would then find. Failing loudly on the first request is
     * far cheaper than discovering it in a month-end reconciliation.
     */
    public static function mismatchReason(): ?string
    {
        $configured = strtolower(trim(Env::get('APP_ENV')));
        if ($configured === '' || $configured === 'local') {
            return null;
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '' || str_contains($host, 'localhost') || str_starts_with($host, '127.')) {
            return null;
        }

        $fromHost = self::fromHost();
        if ($configured === $fromHost) {
            return null;
        }

        return sprintf(
            'APP_ENV is "%s" but the hostname "%s" is a %s host. Fix APP_ENV in api/.env before serving traffic.',
            $configured,
            $host,
            $fromHost
        );
    }
}
