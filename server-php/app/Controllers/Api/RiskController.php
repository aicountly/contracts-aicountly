<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\ContractService;
use App\Services\HealthScoreService;
use App\Services\RiskEngine;
use App\Support\Enums;
use App\Support\Permissions;

/**
 * Risk assessment, findings and the contract health score.
 *
 * Assessing is a write even though it reads like a read: it demotes the
 * previous current assessment and writes a new one, so it is POST and it is
 * rate-limited. Re-running it on every page view would rewrite the assessment
 * history for nothing.
 */
final class RiskController extends BaseController
{
    public function show(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::AI_RISK_VIEW);
        $contractId = $this->intId($id);

        $this->respond(function () use ($ctx, $contractId): array {
            $engine = new RiskEngine($this->db());

            // Confirms the contract is this tenant's before reading anything
            // hanging off it.
            (new ContractService($this->db()))->findOrFail($ctx, $contractId);

            return [
                'assessment' => $engine->currentAssessment($ctx, $contractId),
                'findings'   => $engine->listFindings($ctx, $contractId, $this->findingFilters()),
            ];
        });
    }

    public function assess(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_RISK_VIEW);
        $this->rateLimit('risk.assess', 30, 300);

        $this->respond(fn () => (new RiskEngine($this->db()))->assess($ctx, $this->intId($id)));
    }

    public function health(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_RISK_VIEW);

        $this->respond(fn () => (new HealthScoreService($this->db()))->evaluate($ctx, $this->intId($id)));
    }

    public function reviewFinding(?string $id = null): void
    {
        $ctx  = $this->requirePermission(Permissions::AI_RISK_VIEW);
        $body = $this->body();

        $status = is_string($body['status'] ?? null) ? strtolower(trim($body['status'])) : '';
        if (! in_array($status, ['open', 'accepted', 'mitigated', 'false_positive', 'resolved'], true)) {
            Response::validationError(['status' => 'Choose open, accepted, mitigated, false_positive or resolved.']);
        }

        $notes = isset($body['notes']) && is_string($body['notes'])
            ? mb_substr(trim($body['notes']), 0, 2000)
            : null;

        $this->respond(fn () => (new RiskEngine($this->db()))->reviewFinding($ctx, $this->intId($id), $status, $notes));
    }

    /**
     * Findings across the whole portfolio, for the Risks screen.
     *
     * Joined to contracts rather than listed per contract, because the question
     * this screen answers is "where is our exposure", not "what is wrong with
     * this one agreement".
     */
    public function portfolio(): void
    {
        $ctx  = $this->requirePermission(Permissions::AI_RISK_VIEW);
        $page = Request::pagination(25, 100);
        $pdo  = $this->db();

        $clauses = ['f.environment = :env', 'f.cmp_id = :cmp', 'c.archived_at IS NULL'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        $severity = Enums::coerce(Request::query('severity'), Enums::RISK_SEVERITIES);
        if ($severity !== null) {
            $clauses[]           = 'f.severity = :severity';
            $params['severity']  = $severity;
        }

        $category = Enums::coerce(Request::query('category'), Enums::RISK_CATEGORIES);
        if ($category !== null) {
            $clauses[]           = 'f.risk_category = :category';
            $params['category']  = $category;
        }

        $review = Request::query('review_status');
        if ($review !== null && in_array($review, ['open', 'accepted', 'mitigated', 'false_positive', 'resolved'], true)) {
            $clauses[]         = 'f.review_status = :review';
            $params['review']  = $review;
        } else {
            // The default view is the work still to do; a resolved finding is
            // history and would drown the list.
            $clauses[] = "f.review_status = 'open'";
        }

        $contractId = Request::query('contract_id');
        if ($contractId !== null && ctype_digit($contractId)) {
            $clauses[]              = 'f.contract_id = :contract_id';
            $params['contract_id']  = (int) $contractId;
        }

        if (! $ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            $clauses[]       = '(c.owner_uuid = :self OR c.created_by = :self2)';
            $params['self']  = $ctx->uuid;
            $params['self2'] = $ctx->uuid;
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);

        $countSt = $pdo->prepare(
            "SELECT COUNT(*) FROM contract_risk_findings f JOIN contracts c ON c.id = f.contract_id {$where}"
        );
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        $st = $pdo->prepare(
            "SELECT f.id, f.contract_id, f.rule_key, f.risk_category, f.severity, f.title,
                    f.detail, f.recommendation, f.source_excerpt, f.source_page,
                    f.detected_by, f.ai_confidence, f.review_status, f.created_at,
                    c.contract_number, c.title AS contract_title, c.counterparty_name,
                    c.status AS contract_status
             FROM contract_risk_findings f
             JOIN contracts c ON c.id = f.contract_id
             {$where}
             ORDER BY CASE f.severity
                        WHEN 'critical' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'medium' THEN 3
                        WHEN 'low' THEN 4
                        ELSE 5
                      END,
                      f.created_at DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $page['per_page'], \PDO::PARAM_INT);
        $st->bindValue(':off', $page['offset'], \PDO::PARAM_INT);
        $st->execute();

        Response::paginated($st->fetchAll() ?: [], $total, $page['page'], $page['per_page']);
    }

    /** @return array<string,mixed> */
    private function findingFilters(): array
    {
        return array_filter([
            'severity'      => Enums::coerce(Request::query('severity'), Enums::RISK_SEVERITIES),
            'category'      => Enums::coerce(Request::query('category'), Enums::RISK_CATEGORIES),
            'review_status' => Request::query('review_status'),
        ], static fn ($v): bool => $v !== null && $v !== '');
    }
}
