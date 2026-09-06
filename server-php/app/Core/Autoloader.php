<?php

declare(strict_types=1);

namespace App\Core;

/**
 * PSR-4 autoloader for `App\` → `server-php/app/`.
 *
 * Contracts has no runtime Composer dependencies on purpose: Drive owns object
 * storage, so nothing here needs the AWS SDK, and every integration is a plain
 * cURL call. Shipping our own loader means a cPanel deploy is an rsync and
 * nothing else — no `composer install` step that can be forgotten and no
 * `vendor/` tree to keep in sync with the code beside it.
 *
 * composer.json still exists and still declares the same PSR-4 map, so
 * `composer dump-autoload` works for anyone who prefers it; when a
 * vendor/autoload.php is present the front controller uses that instead.
 */
final class Autoloader
{
    public static function register(string $appDir): void
    {
        $appDir = rtrim($appDir, '/\\');

        spl_autoload_register(static function (string $class) use ($appDir): void {
            if (! str_starts_with($class, 'App\\')) {
                return;
            }

            $relative = substr($class, 4);
            $path     = $appDir . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            // realpath + prefix check: a class name is attacker-influenced only
            // in pathological setups, but "App\..\..\etc\passwd" costing nothing
            // to refuse is the right trade.
            $real = realpath($path);
            if ($real === false || ! str_starts_with($real, $appDir . DIRECTORY_SEPARATOR)) {
                return;
            }

            require_once $real;
        });
    }
}
