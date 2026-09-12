<?php

declare(strict_types=1);

/**
 * Aicountly Contracts API — front controller.
 *
 * Deployed to `<document root>/api`, so it is same-origin with the React app on
 * both contracts.aicountly.com and contracts.gh.aicountly.com. Being same-origin
 * is what keeps the session bootstrap free of CORS entirely.
 *
 * There is no Composer requirement. Contracts has no runtime dependencies —
 * Drive owns object storage, and every integration is a plain cURL call — so a
 * deploy is an rsync and nothing else. A vendor/autoload.php is still used when
 * one is present, for anyone who prefers `composer dump-autoload`.
 */

require_once __DIR__ . '/app/Core/Autoloader.php';

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    \App\Core\Autoloader::register(__DIR__ . '/app');
}

use App\Core\Env;
use App\Core\Response;
use App\Core\Router;
use App\Support\Environment;

Env::setApiRoot(__DIR__);

// Never render a PHP error into the response body. A stack trace tells a caller
// the filesystem layout, the framework, and often a query — errors go to the log
// and the caller gets the envelope.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e): void {
    error_log('[contracts][uncaught] ' . $e::class . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (! headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'success' => false,
        'message' => 'Something went wrong handling that request.',
        'error'   => 'INTERNAL_ERROR',
        'data'    => null,
        'errors'  => ['INTERNAL_ERROR' => 'Something went wrong handling that request.'],
    ], JSON_UNESCAPED_SLASHES);
});

// A fatal (an OOM, a timeout) skips the exception handler, and the caller would
// otherwise receive a 200 with a truncated body — which reads as success.
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log('[contracts][fatal] ' . $error['message'] . ' @ ' . $error['file'] . ':' . $error['line']);

    if (! headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'The server ran out of resources handling that request.',
            'error'   => 'FATAL_ERROR',
            'data'    => null,
            'errors'  => [],
        ], JSON_UNESCAPED_SLASHES);
    }
});

// --- CORS -------------------------------------------------------------------
// Both deployed environments are same-origin with their app, so nothing is sent
// there. This exists for `npm run dev` on localhost against a deployed API, and
// the allow-list is exact — no wildcard, no origin reflection.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (is_string($origin) && $origin !== '') {
    $allowed = Env::list('CORS_ALLOWED_ORIGINS');
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-AIC-CMP-ID, X-AIC-FY-ID, X-AIC-BO-ID');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 600');
        header('Vary: Origin');
    }
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// APP_ENV disagreeing with the hostname means a sandbox .env has been copied
// onto the production host. Serving anyway would write production traffic into
// rows tagged `sandbox`, which no report would then find.
$mismatch = Environment::mismatchReason();
if ($mismatch !== null) {
    error_log('[contracts][config] ' . $mismatch);
    Response::error('ENVIRONMENT_MISCONFIGURED', $mismatch, 503);
}

require_once __DIR__ . '/app/Config/Routes.php';

Router::getInstance()->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    Router::requestPath()
);
