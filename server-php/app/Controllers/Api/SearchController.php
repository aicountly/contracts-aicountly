<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\SearchService;
use App\Support\Permissions;

/**
 * The search box in the header.
 *
 * Reading a contract is the only grant it takes: the results are contracts,
 * library clauses and documents the caller could already open, and the service
 * applies the same tenant scope and owner narrowing the repository does. What
 * it does not do is widen anyone's reach — a hit the caller cannot open must
 * not appear here, or the box becomes a way to enumerate titles.
 *
 * Rate-limited because a type-ahead box sends a request per keystroke and each
 * one is several index lookups across three tables.
 */
final class SearchController extends BaseController
{
    /**
     * Below this a trigram match is effectively every row in the tenant, and
     * the ranking that follows is noise the user has to read past.
     */
    private const MIN_TERM = 2;

    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 50;

    public function index(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        // Looser than the export budget on purpose: this is a per-keystroke
        // endpoint, and a limit tight enough to matter would fire on ordinary
        // typing rather than on abuse.
        $this->rateLimit('search', 120, 60);

        $term  = mb_substr(trim((string) (Request::query('q') ?? '')), 0, 200);
        $limit = self::limit(Request::query('limit'));

        if (mb_strlen($term) < self::MIN_TERM) {
            // The empty shape rather than a 422: the box is empty on first
            // paint and again while the user is deleting, and neither of those
            // is a mistake worth showing them an error for.
            Response::success(['contracts' => [], 'clauses' => [], 'documents' => [], 'total' => 0]);
        }

        $this->respond(fn (): array => (new SearchService($this->db()))->search($ctx, $term, $limit));
    }

    /** Per-section result count, pulled into range rather than refused. */
    private static function limit(?string $value): int
    {
        if ($value === null || preg_match('/^\d{1,9}$/', trim($value)) !== 1) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, (int) trim($value)));
    }
}
