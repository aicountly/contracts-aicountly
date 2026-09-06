<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Permissions;
use App\Support\TenantContext;
use PDO;

/**
 * The header search box: contracts, clauses and document text in one answer.
 *
 * Two matching strategies, because neither alone is enough. The tsvector on
 * `contracts.search_vector` answers word queries with a ranking — it knows that
 * "renewals" and "renewal" are the same lexeme and that a hit in the title
 * outranks one in the notes. Trigram indexes answer the substring queries a
 * lexeme match cannot: somebody typing "acme" wants "Acmecorp Industries", and
 * to_tsquery has never heard of it. Both indexes already exist (013_search.sql);
 * this class is what uses them.
 *
 * Results are grouped rather than merged into one ranked list. A clause and a
 * contract are not comparable results, and interleaving them by score produces
 * an order that looks arbitrary to the person reading it.
 *
 * Everything is tenant-scoped and narrowed by the same row-level rule the
 * repository applies. A search box that returns titles the caller cannot open
 * is an enumeration tool with a magnifying glass on it.
 */
final class SearchService
{
    /** Characters of context either side of a hit in a document body. */
    private const SNIPPET_RADIUS = 90;

    private const SNIPPET_MAX = 240;

    public function __construct(private PDO $pdo)
    {
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    /**
     * Search everything the caller may see.
     *
     * @return array{contracts: list<array<string,mixed>>, clauses: list<array<string,mixed>>,
     *               documents: list<array<string,mixed>>, total: int, term: string}
     */
    public function search(TenantContext $ctx, string $term, int $limit = 10): array
    {
        $term  = trim($term);
        $limit = max(1, min(50, $limit));

        if (mb_strlen($term) < 2) {
            return ['contracts' => [], 'clauses' => [], 'documents' => [], 'total' => 0, 'term' => $term];
        }

        $contracts = $this->contracts($ctx, $term, $limit);
        $clauses   = $this->clauses($ctx, $term, $limit);
        $documents = $this->documents($ctx, $term, $limit);

        return [
            'contracts' => $contracts,
            'clauses'   => $clauses,
            'documents' => $documents,
            'total'     => count($contracts) + count($clauses) + count($documents),
            'term'      => $term,
        ];
    }

    /**
     * Contracts by number, title, counterparty, tag or free text.
     *
     * `plainto_tsquery`, not `to_tsquery`: a user typing "acme & co" is
     * searching, not writing a boolean expression, and to_tsquery answers that
     * with a syntax error.
     *
     * @return list<array<string,mixed>>
     */
    private function contracts(TenantContext $ctx, string $term, int $limit): array
    {
        [$visibility, $visibilityParams] = $this->visibility($ctx);

        $sql = "SELECT c.id, c.uuid, c.contract_number, c.title, c.status, c.counterparty_name,
                       c.expiry_date, c.total_value, c.currency, c.risk_level, c.owner_uuid,
                       c.description, c.commercial_summary,
                       ct.name AS contract_type_name,
                       ts_rank(c.search_vector, plainto_tsquery('english', :q_ts)) AS rank,
                       (SELECT string_agg(t.name, ', ' ORDER BY t.name)
                          FROM contract_tag_map m
                          JOIN contract_tags t ON t.id = m.tag_id
                         WHERE m.contract_id = c.id) AS tags
                FROM contracts c
                LEFT JOIN contract_types ct ON ct.id = c.contract_type_id
                WHERE c.environment = :env AND c.cmp_id = :cmp AND c.archived_at IS NULL
                  {$visibility}
                  AND (
                        c.search_vector @@ plainto_tsquery('english', :q_ts2)
                     OR c.contract_number ILIKE :q_like
                     OR c.title ILIKE :q_like2
                     OR c.counterparty_name ILIKE :q_like3
                     OR EXISTS (
                            SELECT 1 FROM contract_tag_map m
                            JOIN contract_tags t ON t.id = m.tag_id
                            WHERE m.contract_id = c.id AND t.name ILIKE :q_like4
                        )
                      )
                ORDER BY rank DESC NULLS LAST, c.updated_at DESC, c.id DESC
                LIMIT :lim";

        $st = $this->pdo->prepare($sql);
        foreach (array_merge([
            'env'     => $ctx->environment,
            'cmp'     => $ctx->cmpId,
            'q_ts'    => $term,
            'q_ts2'   => $term,
            'q_like'  => '%' . self::escapeLike($term) . '%',
            'q_like2' => '%' . self::escapeLike($term) . '%',
            'q_like3' => '%' . self::escapeLike($term) . '%',
            'q_like4' => '%' . self::escapeLike($term) . '%',
        ], $visibilityParams) as $key => $value) {
            $st->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        return array_map(static function (array $r) use ($term): array {
            return [
                'id'                 => (int) $r['id'],
                'uuid'               => $r['uuid'],
                'contract_number'    => $r['contract_number'],
                'title'              => $r['title'],
                'status'             => $r['status'],
                'counterparty_name'  => $r['counterparty_name'],
                'contract_type_name' => $r['contract_type_name'],
                'expiry_date'        => $r['expiry_date'],
                'total_value'        => $r['total_value'],
                'currency'           => $r['currency'],
                'risk_level'         => $r['risk_level'],
                'owner_uuid'         => $r['owner_uuid'],
                'tags'               => $r['tags'] === null ? [] : array_map('trim', explode(',', (string) $r['tags'])),
                'link_path'          => '/contracts/' . (int) $r['id'],
                // The excerpt comes from the prose fields, not the title: the
                // title is already shown beside it, and repeating it there
                // tells the reader nothing about why this row matched.
                'snippet'            => self::snippet(
                    trim(((string) ($r['commercial_summary'] ?? '')) . ' ' . ((string) ($r['description'] ?? ''))),
                    $term
                ),
            ];
        }, $st->fetchAll() ?: []);
    }

    /**
     * Clause hits, from the library and from clauses inside contracts.
     *
     * One UNION rather than two sections in the response: to the person
     * searching, "indemnity" is one idea, and whether the wording they want is
     * the company standard or the version that ended up in a particular
     * contract is what the `source` column tells them.
     *
     * @return list<array<string,mixed>>
     */
    private function clauses(TenantContext $ctx, string $term, int $limit): array
    {
        [$visibility, $visibilityParams] = $this->visibility($ctx);

        $sql = "(
                    SELECT 'library' AS source, l.id, l.name AS heading, l.standard_text AS body,
                           NULL::bigint AS contract_id, NULL::varchar AS contract_number,
                           cat.name AS category_name
                    FROM clause_library l
                    LEFT JOIN clause_categories cat ON cat.id = l.category_id
                    WHERE l.environment = :env AND l.cmp_id = :cmp AND l.archived_at IS NULL
                      AND (l.name ILIKE :q_like OR l.standard_text ILIKE :q_like2)
                    ORDER BY l.name
                    LIMIT :lim
                )
                UNION ALL
                (
                    SELECT 'contract' AS source, cc.id, cc.heading, cc.body_text AS body,
                           cc.contract_id, c.contract_number,
                           cat.name AS category_name
                    FROM contract_clauses cc
                    JOIN contracts c ON c.id = cc.contract_id
                    LEFT JOIN clause_categories cat ON cat.id = cc.category_id
                    WHERE cc.environment = :env2 AND cc.cmp_id = :cmp2 AND c.archived_at IS NULL
                      {$visibility}
                      AND (cc.heading ILIKE :q_like3 OR cc.body_text ILIKE :q_like4)
                    ORDER BY cc.id DESC
                    LIMIT :lim2
                )";

        $st = $this->pdo->prepare($sql);
        foreach (array_merge([
            'env'     => $ctx->environment,
            'cmp'     => $ctx->cmpId,
            'env2'    => $ctx->environment,
            'cmp2'    => $ctx->cmpId,
            'q_like'  => '%' . self::escapeLike($term) . '%',
            'q_like2' => '%' . self::escapeLike($term) . '%',
            'q_like3' => '%' . self::escapeLike($term) . '%',
            'q_like4' => '%' . self::escapeLike($term) . '%',
        ], $visibilityParams) as $key => $value) {
            $st->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':lim2', $limit, PDO::PARAM_INT);
        $st->execute();

        return array_map(static function (array $r) use ($term): array {
            $contractId = $r['contract_id'] === null ? null : (int) $r['contract_id'];

            return [
                'source'          => (string) $r['source'],
                'id'              => (int) $r['id'],
                'heading'         => $r['heading'],
                'category_name'   => $r['category_name'],
                'contract_id'     => $contractId,
                'contract_number' => $r['contract_number'],
                'link_path'       => $contractId === null ? '/clauses/' . (int) $r['id'] : '/contracts/' . $contractId . '#clauses',
                'snippet'         => self::snippet((string) ($r['body'] ?? ''), $term),
            ];
        }, array_slice($st->fetchAll() ?: [], 0, $limit * 2));
    }

    /**
     * Hits inside extracted document text.
     *
     * Only the current version of each document, because every superseded
     * version of a long agreement matches the same words and would push
     * everything else off the page.
     *
     * The extracted text is deliberately not folded into the contract's own
     * tsvector (see 013_search.sql) — sixty pages of PDF would drown the title
     * and number in every ranking — so it is matched here on its trigram index
     * instead.
     *
     * @return list<array<string,mixed>>
     */
    private function documents(TenantContext $ctx, string $term, int $limit): array
    {
        [$visibility, $visibilityParams] = $this->visibility($ctx);

        $sql = "SELECT v.id AS version_id, v.version_no, v.filename, v.version_status,
                       v.extracted_text, d.id AS document_id, d.title AS document_title,
                       c.id AS contract_id, c.contract_number, c.title AS contract_title
                FROM contract_document_versions v
                JOIN contract_documents d ON d.id = v.document_id
                JOIN contracts c ON c.id = d.contract_id
                WHERE v.environment = :env AND v.cmp_id = :cmp
                  AND c.archived_at IS NULL
                  {$visibility}
                  AND v.extracted_text IS NOT NULL
                  AND (d.current_version_id IS NULL OR d.current_version_id = v.id)
                  AND (v.extracted_text ILIKE :q_like OR v.filename ILIKE :q_like2 OR d.title ILIKE :q_like3)
                ORDER BY v.created_at DESC, v.id DESC
                LIMIT :lim";

        $st = $this->pdo->prepare($sql);
        foreach (array_merge([
            'env'     => $ctx->environment,
            'cmp'     => $ctx->cmpId,
            'q_like'  => '%' . self::escapeLike($term) . '%',
            'q_like2' => '%' . self::escapeLike($term) . '%',
            'q_like3' => '%' . self::escapeLike($term) . '%',
        ], $visibilityParams) as $key => $value) {
            $st->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        return array_map(static function (array $r) use ($term): array {
            return [
                'version_id'      => (int) $r['version_id'],
                'version_no'      => (int) $r['version_no'],
                'version_status'  => $r['version_status'],
                'document_id'     => (int) $r['document_id'],
                'document_title'  => $r['document_title'],
                'filename'        => $r['filename'],
                'contract_id'     => (int) $r['contract_id'],
                'contract_number' => $r['contract_number'],
                'contract_title'  => $r['contract_title'],
                'link_path'       => '/contracts/' . (int) $r['contract_id'] . '#documents',
                'snippet'         => self::snippet((string) ($r['extracted_text'] ?? ''), $term),
            ];
        }, $st->fetchAll() ?: []);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The row-level visibility rule, as an " AND (...)" fragment.
     *
     * The same predicate ContractService applies. Written as a fragment rather
     * than applied in place so the three queries above cannot drift apart —
     * three copies of this SQL would eventually not agree, and the one that
     * disagreed would be the leak.
     *
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function visibility(TenantContext $ctx): array
    {
        if ($ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            return ['', []];
        }

        return [
            'AND (c.owner_uuid = :vis1 OR c.created_by = :vis2
                  OR EXISTS (
                      SELECT 1 FROM contract_approval_assignments a
                      JOIN contract_approval_instances i ON i.id = a.instance_id
                      WHERE i.contract_id = c.id AND a.approver_uuid = :vis3
                  ))',
            ['vis1' => $ctx->uuid, 'vis2' => $ctx->uuid, 'vis3' => $ctx->uuid],
        ];
    }

    /**
     * Neutralise LIKE's own wildcards in a user's search term.
     *
     * Without this a term of `%` matches every row in the tenant, and a term
     * ending in `\` makes PostgreSQL read the closing quote as escaped. The
     * value is still bound; this is about the term meaning what the user typed.
     */
    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    /**
     * A readable window of text around the first hit.
     *
     * Built here rather than with ts_headline because the match that found this
     * row was a substring one — ts_headline works from lexemes, so on a hit
     * inside a word it would return the opening sentence of a sixty-page
     * document and call it the excerpt.
     */
    private static function snippet(string $text, string $term): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }

        $position = $term === '' ? false : mb_stripos($text, $term);
        if ($position === false) {
            return mb_substr($text, 0, self::SNIPPET_MAX) . (mb_strlen($text) > self::SNIPPET_MAX ? '…' : '');
        }

        $start   = max(0, $position - self::SNIPPET_RADIUS);
        $snippet = mb_substr($text, $start, self::SNIPPET_MAX);

        return ($start > 0 ? '…' : '') . $snippet . ($start + self::SNIPPET_MAX < mb_strlen($text) ? '…' : '');
    }
}
