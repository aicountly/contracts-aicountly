<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Manage\ManageClient;
use App\Modules\Portal\PortalClient;
use App\Services\RateLimiter;
use App\Services\RoleService;
use App\Support\Environment;
use App\Support\DomainException;
use App\Support\Permissions;
use App\Support\TenantContext;
use App\Support\ValidationFailed;
use PDO;

/**
 * The gate every Contracts endpoint passes through.
 *
 * Three questions get answered here and nowhere else:
 *
 *   1. Is this session live?          — the portal decides
 *   2. May this user act for cmp_id?  — Manage decides
 *   3. What may they do inside Contracts? — `contract_user_roles` decides
 *
 * Nothing downstream repeats those checks, which is why nothing downstream is
 * allowed to build a TenantContext of its own. Every query in every service
 * filters on `$ctx->cmpId` and `$ctx->environment`; a query that does not is a
 * cross-tenant leak, and CrossTenantIsolationTest exists to catch one.
 */
abstract class BaseController
{
    private ?TenantContext $context = null;

    /**
     * Authenticated caller with a validated company context.
     *
     * Memoised because a single action may ask for it more than once and each
     * miss would be two outbound HTTP calls.
     */
    protected function requireContext(): TenantContext
    {
        if ($this->context !== null) {
            return $this->context;
        }

        $mismatch = Environment::mismatchReason();
        if ($mismatch !== null) {
            Response::error('ENVIRONMENT_MISCONFIGURED', $mismatch, 503);
        }

        $sesKey = Request::bearerToken();
        if ($sesKey === '') {
            Response::unauthorized('Missing bearer session key.');
        }

        $session = PortalClient::validateSesKey($sesKey);
        if ($session === null) {
            Response::unauthorized();
        }

        $uuid = trim((string) ($session['uuid_aictly'] ?? $session['uuid'] ?? ''));
        if ($uuid === '') {
            Response::unauthorized('The portal did not identify this session.');
        }

        $cmpId = $this->contextId('cmp_id', 'X-AIC-CMP-ID');
        $fyId  = $this->contextId('fy_id', 'X-AIC-FY-ID');
        $boId  = $this->contextId('bo_id', 'X-AIC-BO-ID');

        if ($cmpId === null) {
            Response::error('MISSING_COMPANY_CONTEXT', 'cmp_id is required.', 400);
        }
        if ($fyId === null) {
            Response::error('MISSING_FINANCIAL_YEAR', 'fy_id is required.', 400);
        }
        if ($boId === null) {
            Response::error('MISSING_BRANCH_CONTEXT', 'bo_id is required.', 400);
        }

        // Unlike Drive there is no cmp_id=0 sentinel here. Drive has personal
        // documents that belong to no company; a contract is always an
        // agreement a company is party to, so "no company" is never valid and
        // accepting it would create rows no company-scoped query could reach.
        if ($cmpId === '0') {
            Response::error('MISSING_COMPANY_CONTEXT', 'Contracts are always company-scoped. Select a company first.', 400);
        }

        $company = ManageClient::companyInfo($sesKey, $cmpId);
        if ($company === null) {
            Response::error('COMPANY_ACCESS_DENIED', 'You do not have access to this company.', 403);
        }

        if (! ManageClient::financialYearIsValid($company, $fyId)) {
            Response::error('INVALID_FINANCIAL_YEAR', 'fy_id is not valid for this company.', 400);
        }
        if (! ManageClient::branchIsValid($company, $boId)) {
            Response::error('INVALID_BRANCH', 'bo_id is not valid for this company.', 400);
        }

        $environment = Environment::resolve();
        $roles       = RoleService::rolesFor($environment, (int) $cmpId, $uuid, $company);

        return $this->context = new TenantContext(
            uuid: $uuid,
            sesKey: $sesKey,
            cmpId: (int) $cmpId,
            fyId: (int) $fyId,
            boId: (int) $boId,
            environment: $environment,
            company: $company,
            permissions: Permissions::forRoles($roles),
            roles: $roles,
        );
    }

    /**
     * Context plus a permission check.
     *
     * The permission is checked before the action runs rather than inside it,
     * so a new endpoint that forgets to name one fails review rather than
     * silently defaulting to open.
     */
    protected function requirePermission(string $permission): TenantContext
    {
        $ctx = $this->requireContext();
        if (! $ctx->has($permission)) {
            Response::forbidden('Your Contracts role does not allow this (' . $permission . ').');
        }

        return $ctx;
    }

    /** @param list<string> $permissions */
    protected function requireAnyPermission(array $permissions): TenantContext
    {
        $ctx = $this->requireContext();
        if (! $ctx->hasAny($permissions)) {
            Response::forbidden('Your Contracts role does not allow this.');
        }

        return $ctx;
    }

    protected function db(): PDO
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            Response::error('DB_UNAVAILABLE', Database::unavailableMessage(), 503);
        }

        return $pdo;
    }

    /**
     * Spend one unit of this caller's budget for this route, or refuse.
     *
     * Keyed on the route template rather than the path so `/contracts/1` and
     * `/contracts/2` share a budget — otherwise walking ids would reset the
     * counter on every request and the limit would mean nothing.
     */
    protected function rateLimit(string $bucket, int $limit, int $windowSeconds): void
    {
        $ctx = $this->requireContext();
        $key = $bucket . '|' . $ctx->environment . '|' . $ctx->cmpId . '|' . $ctx->uuid;

        $verdict = RateLimiter::hit($key, $limit, $windowSeconds);
        if (! $verdict['allowed']) {
            header('Retry-After: ' . $verdict['retry_after']);
            Response::error(
                'RATE_LIMITED',
                sprintf('Too many requests. Try again in %d seconds.', $verdict['retry_after']),
                429
            );
        }
    }

    /** Rate-limit key for the route that matched, for endpoints with no better bucket. */
    protected function routeBucket(): string
    {
        $route = Router::matchedRoute();

        return $route !== '' ? $route : static::class;
    }

    /**
     * A required positive integer id from the URL.
     *
     * Anything else is a 404, not a 400: a caller probing `/contracts/abc` and
     * a caller probing `/contracts/999999` should not be able to tell the two
     * apart from the status code.
     */
    protected function intId(?string $raw): int
    {
        if ($raw === null || ! preg_match('/^\d{1,19}$/', $raw)) {
            Response::notFound();
        }

        $id = (int) $raw;
        if ($id < 1) {
            Response::notFound();
        }

        return $id;
    }

    /** @return array<string,mixed> */
    protected function body(): array
    {
        return Request::jsonBody();
    }

    /**
     * Run a service call and turn its failure modes into the right HTTP answer.
     *
     * Without this every action repeats the same three catch blocks, and the
     * one that forgets `ValidationFailed` turns a fixable form error into a
     * 500. Anything unexpected becomes a generic 500 with a logged detail — a
     * stack trace must never reach a production caller.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    protected function run(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (ValidationFailed $e) {
            Response::validationError($e->errors, $e->getMessage());
        } catch (DomainException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->status);
        } catch (\PDOException $e) {
            error_log('[contracts][db] ' . $e->getMessage());
            Response::error('DB_ERROR', 'The database refused that request.', 500);
        } catch (\Throwable $e) {
            error_log('[contracts] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Response::error('INTERNAL_ERROR', 'Something went wrong handling that request.', 500);
        }
    }

    /**
     * Answer with whatever the service returned.
     *
     * @param callable(): mixed $fn
     */
    protected function respond(callable $fn, int $status = 200): void
    {
        Response::success($this->run($fn), $status);
    }

    /**
     * Read `cmp_id` / `fy_id` / `bo_id` from query, header or JSON body.
     *
     * Three sources because different callers use different ones: the SPA's
     * fetch wrapper sends headers, a link opened in a new tab carries query
     * params, and server-to-server callers put them in the body.
     */
    private function contextId(string $field, string $headerName): ?string
    {
        $candidates = [
            Request::query($field),
            Request::header($headerName),
        ];

        $body = Request::jsonBody();
        if (isset($body[$field]) && (is_string($body[$field]) || is_int($body[$field]))) {
            $candidates[] = (string) $body[$field];
        }

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $value = trim((string) $candidate);
            if ($value !== '' && ctype_digit($value)) {
                return $value;
            }
        }

        return null;
    }
}
