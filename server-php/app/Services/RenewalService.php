<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Dates;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use App\Support\Validator;
use PDO;

/**
 * The renewal clock: one row per renewal cycle of a contract, and the queue
 * that surfaces the cycles somebody has to decide on.
 *
 * A cycle is a record *about* the contract, never a rewrite of it. Renewing
 * cycle 1 closes that row as `renewed`, extends the contract's expiry and opens
 * cycle 2 — so "when did we decide to renew, who decided, and what was the term
 * before that decision" survives every later renewal.
 *
 * The dates that drive the queue (`notice_deadline`, `decision_due_date`) are
 * stored on the cycle rather than recomputed from the contract on read: the
 * nightly sweep filters on them across every tenant, and a stale-but-indexed
 * column is repaired by the next renewal write, whereas a full scan is
 * permanent.
 */
final class RenewalService
{
    /** Columns the pipeline may sort by. Anything else is ignored rather than interpolated. */
    private const SORTABLE = [
        'decision_due' => 'r.decision_due_date',
        'notice'       => 'r.notice_deadline',
        'expiry'       => 'r.current_expiry',
        'value'        => 'c.total_value',
        'title'        => 'c.title',
        'created_at'   => 'r.created_at',
    ];

    /** Cycles nobody has decided yet — the ones the queue and the sweep care about. */
    private const OPEN_STATUSES = ['not_yet_due', 'review_due', 'under_review'];

    /**
     * Mirrors `ck_renewal_recommendation`. Not in Enums because a recommendation
     * is advice about a decision rather than a state anything else branches on;
     * if a second caller ever needs it, it moves there and the CHECK moves with
     * it in the same migration.
     */
    private const RECOMMENDATIONS = ['renew', 'renegotiate', 'terminate', 'review_manually'];

    /** Mirrors `ck_renewal_source`. */
    private const RECOMMENDATION_SOURCES = ['rules', 'ai', 'manual'];

    /** Mirrors `ck_renewal_decision`. */
    private const DECISIONS = ['renew', 'renegotiate', 'terminate', 'defer'];

    /** Term to assume when neither the cycle nor the contract states one. */
    private const DEFAULT_TERM_MONTHS = 12;

    private AuditService $audit;

    private ActivityService $activity;

    public function __construct(private PDO $pdo)
    {
        $this->audit    = new AuditService($pdo);
        $this->activity = new ActivityService($pdo);
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // Cycles
    // -----------------------------------------------------------------------

    /**
     * Open cycle 1 for a contract that has just gone active.
     *
     * Idempotent because it is called from a status change that can legitimately
     * happen twice — a contract archived and restored to active, a retried
     * request — and a second cycle 1 would either violate
     * `uq_contract_renewal_cycle` or, worse, put the same contract in the queue
     * twice. An existing cycle is returned as-is rather than recomputed: its
     * dates may have been moved deliberately by a deferral.
     *
     * @return array<string,mixed>|null the cycle, or null when the contract has
     *         no expiry date and therefore nothing to renew
     */
    public function ensureCycle(TenantContext $ctx, int $contractId): ?array
    {
        $contract = $this->contractOrFail($ctx, $contractId);

        $existing = $this->latestCycle($ctx, $contractId);
        if ($existing !== null) {
            return $existing;
        }

        // A perpetual or evergreen agreement has no end date to count down to.
        // Putting it in the renewal queue would mean an item nobody can ever
        // close.
        $expiry = self::asDate($contract['expiry_date'] ?? null);
        if ($expiry === null) {
            return null;
        }

        return $this->openCycle($ctx, $contract, 1, $expiry, $this->termMonthsFor($contract, null));
    }

    /** @return array<string,mixed>|null */
    public function find(TenantContext $ctx, int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT r.*, c.contract_number, c.title AS contract_title, c.status AS contract_status,
                    c.counterparty_name, c.auto_renewal, c.currency, c.total_value,
                    c.notice_period_days, c.renewal_type, c.renewal_frequency
             FROM contract_renewals r
             JOIN contracts c ON c.id = r.contract_id
             WHERE r.id = ? AND r.environment = ? AND r.cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findOrFail(TenantContext $ctx, int $id): array
    {
        $row = $this->find($ctx, $id);
        if ($row === null) {
            throw DomainException::notFound('Renewal cycle not found.');
        }

        return $row;
    }

    /** Every cycle of one contract, newest cycle first. @return list<array<string,mixed>> */
    public function listForContract(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT r.* FROM contract_renewals r
             WHERE r.contract_id = ? AND r.environment = ? AND r.cmp_id = ?
             ORDER BY r.cycle_no DESC'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        return array_map(fn (array $r): array => $this->hydrate($r), $st->fetchAll() ?: []);
    }

    /**
     * The renewal queue.
     *
     * @param array<string,mixed> $filters bucket, status, owner_uuid, contract_type_id, q
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function pipeline(TenantContext $ctx, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildPipelineWhere($ctx, $filters);

        $sortKey = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'decision_due';
        $column  = self::SORTABLE[$sortKey] ?? self::SORTABLE['decision_due'];
        $dir     = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // NULLS LAST in both directions: a cycle with no decision date is the
        // least urgent thing in a queue sorted by urgency, whichever way round
        // the caller reads it.
        $order = "{$column} {$dir} NULLS LAST, r.id {$dir}";

        $countSt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM contract_renewals r JOIN contracts c ON c.id = r.contract_id {$where}"
        );
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sql = "SELECT r.*, c.contract_number, c.title AS contract_title, c.status AS contract_status,
                       c.counterparty_name, c.auto_renewal, c.currency, c.total_value,
                       c.notice_period_days, c.renewal_type, c.renewal_frequency,
                       c.contract_type_id, ct.name AS contract_type_name
                FROM contract_renewals r
                JOIN contracts c ON c.id = r.contract_id
                LEFT JOIN contract_types ct ON ct.id = c.contract_type_id
                {$where}
                ORDER BY {$order}
                LIMIT :lim OFFSET :off";

        $st = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        $items = array_map(fn (array $r): array => $this->hydrate($r), $st->fetchAll() ?: []);

        return ['items' => $items, 'total' => $total];
    }

    // -----------------------------------------------------------------------
    // Decisions
    // -----------------------------------------------------------------------

    /**
     * Record what the business decided about a cycle.
     *
     * `renew` is the only decision that touches the contract: it extends the
     * expiry date and opens the next cycle in the same transaction, so a crash
     * can never leave a contract extended with nobody watching the new term.
     * `terminate` deliberately does not — ending a contract is a termination
     * record with its own approval and notice, which is TerminationService's
     * job, and doing it here would bypass both.
     *
     * @param array<string,mixed> $body notes, renewal_term_months, proposed_start,
     *                                  proposed_expiry, defer_until
     * @return array<string,mixed> the decided cycle, plus `next_cycle` when one was opened
     */
    public function recordDecision(TenantContext $ctx, int $renewalId, string $decision, array $body): array
    {
        $renewal = $this->findOrFail($ctx, $renewalId);

        if (! in_array($decision, self::DECISIONS, true)) {
            throw DomainException::badRequest('Choose one of: ' . implode(', ', self::DECISIONS) . '.');
        }

        $status = (string) $renewal['status'];
        if (in_array($status, ['renewed', 'closed'], true)) {
            throw DomainException::conflict(
                'This renewal cycle is already closed. Decide on the current cycle instead.',
                'RENEWAL_CYCLE_CLOSED'
            );
        }

        $v            = new Validator($body);
        $notes        = $v->optionalText('notes', 5000);
        $termMonths   = $v->optionalInt('renewal_term_months', 1, 600);
        $deferUntil   = $v->optionalDate('defer_until');
        $proposedFrom = $v->optionalDate('proposed_start');
        $proposedTo   = $v->optionalDate('proposed_expiry');
        $v->assert();

        return Database::transaction($this->pdo, function (PDO $pdo) use (
            $ctx,
            $renewal,
            $renewalId,
            $decision,
            $status,
            $notes,
            $termMonths,
            $deferUntil,
            $proposedFrom,
            $proposedTo
        ): array {
            $contractId = (int) $renewal['contract_id'];
            $next       = null;

            if ($decision === 'renew') {
                $next = $this->renew($ctx, $renewal, $termMonths, $proposedFrom, $proposedTo);
            }

            // A deferral is not an answer, so the cycle stays open and keeps its
            // owner; only the date it comes back is moved. It is held at
            // under_review rather than returned to not_yet_due so the nightly
            // sweep — which only ever touches not_yet_due — cannot re-raise it
            // the same night the deferral was recorded.
            $newStatus = match ($decision) {
                'renew'       => 'renewed',
                'renegotiate' => 'renegotiate',
                'terminate'   => 'terminate',
                'defer'       => 'under_review',
            };

            $decisionDue = $decision === 'defer'
                ? ($deferUntil ?? Dates::addDays(Dates::today(), 30))
                : $renewal['decision_due_date'];

            $pdo->prepare(
                'UPDATE contract_renewals
                 SET status = ?, decision = ?, decision_by = ?, decision_at = CURRENT_TIMESTAMP,
                     decision_notes = COALESCE(?, decision_notes),
                     decision_due_date = ?,
                     renegotiation_required = ?,
                     proposed_start = COALESCE(?, proposed_start),
                     proposed_expiry = COALESCE(?, proposed_expiry),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([
                $newStatus,
                $decision,
                $ctx->uuid,
                $notes,
                $decisionDue,
                $decision === 'renegotiate' ? 'true' : 'false',
                $proposedFrom,
                $proposedTo,
                $renewalId,
                $ctx->environment,
                $ctx->cmpId,
            ]);

            $this->audit->log($ctx, 'renewal', $renewalId, 'renewal.decision', $contractId, [
                'status'   => ['from' => $status, 'to' => $newStatus],
                'decision' => ['from' => $renewal['decision'], 'to' => $decision],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'renewal.decision.' . $decision,
                sprintf('Renewal cycle %d decision: %s', (int) $renewal['cycle_no'], Enums::label($decision)),
                array_filter([
                    'cycle_no'   => (int) $renewal['cycle_no'],
                    'notes'      => $notes,
                    'next_cycle' => $next === null ? null : (int) $next['cycle_no'],
                ], static fn ($value): bool => $value !== null)
            );

            $decided = $this->findOrFail($ctx, $renewalId);
            if ($next !== null) {
                $decided['next_cycle'] = $next;
            }

            return $decided;
        });
    }

    /**
     * Attach advice to a cycle — from the rules engine, from AI, or typed by a person.
     *
     * Refused once a decision exists: advice arriving after the fact reads, in
     * the UI and in the audit trail, as if it informed a decision it never saw.
     *
     * @return array<string,mixed>
     */
    public function setRecommendation(
        TenantContext $ctx,
        int $renewalId,
        string $recommendation,
        string $reason,
        string $source
    ): array {
        $renewal = $this->findOrFail($ctx, $renewalId);

        if (! in_array($recommendation, self::RECOMMENDATIONS, true)) {
            throw DomainException::badRequest('Choose one of: ' . implode(', ', self::RECOMMENDATIONS) . '.');
        }
        if (! in_array($source, self::RECOMMENDATION_SOURCES, true)) {
            throw DomainException::badRequest('Choose one of: ' . implode(', ', self::RECOMMENDATION_SOURCES) . '.');
        }
        if ($renewal['decision'] !== null) {
            throw DomainException::conflict(
                'This cycle has already been decided; a recommendation would be recorded after the fact.',
                'RENEWAL_ALREADY_DECIDED'
            );
        }

        $this->pdo->prepare(
            'UPDATE contract_renewals
             SET recommendation = ?, recommendation_reason = ?, recommendation_source = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([
            $recommendation,
            mb_substr(trim($reason), 0, 5000),
            $source,
            $renewalId,
            $ctx->environment,
            $ctx->cmpId,
        ]);

        $this->audit->log($ctx, 'renewal', $renewalId, 'renewal.recommendation', (int) $renewal['contract_id'], [
            'recommendation' => ['from' => $renewal['recommendation'], 'to' => $recommendation],
            'source'         => ['from' => $renewal['recommendation_source'], 'to' => $source],
        ]);

        return $this->findOrFail($ctx, $renewalId);
    }

    /**
     * Close every open cycle of a contract that has stopped being renewable.
     *
     * Called when a termination completes. Without it a terminated contract sits
     * in the renewal queue forever, and the one thing a queue must not contain
     * is work that can never be done.
     */
    public function closeOpenCycles(TenantContext $ctx, int $contractId, string $reason): int
    {
        $names  = [];
        $params = [];
        foreach (self::OPEN_STATUSES as $i => $status) {
            $names[]            = ':os' . $i;
            $params['os' . $i]  = $status;
        }

        $st = $this->pdo->prepare(
            'UPDATE contract_renewals
             SET status = \'closed\', notes = COALESCE(notes || E\'\\n\', \'\') || :reason,
                 updated_at = CURRENT_TIMESTAMP
             WHERE contract_id = :cid AND environment = :env AND cmp_id = :cmp
               AND status IN (' . implode(', ', $names) . ')
             RETURNING id'
        );
        $st->execute($params + [
            'reason' => mb_substr($reason, 0, 1000),
            'cid'    => $contractId,
            'env'    => $ctx->environment,
            'cmp'    => $ctx->cmpId,
        ]);

        $closed = $st->fetchAll() ?: [];
        foreach ($closed as $row) {
            $this->audit->log($ctx, 'renewal', (int) $row['id'], 'renewal.closed', $contractId, [
                'status' => ['from' => 'open', 'to' => 'closed'],
                'reason' => ['from' => null, 'to' => $reason],
            ]);
        }

        return count($closed);
    }

    // -----------------------------------------------------------------------
    // The nightly sweep
    // -----------------------------------------------------------------------

    /**
     * Move cycles into review as their deadlines approach.
     *
     * Runs per company rather than as one statement across the estate because
     * the lead time is `contract_settings.expiry_alert_days`, which each company
     * sets for itself.
     *
     * Only `not_yet_due` rows are touched, which is what makes the sweep safe to
     * run as often as anyone likes: a cycle someone has already looked at, or
     * decided, or deferred, is past that status and stays where it is.
     *
     * @return array{opened: int, notice_due: int}
     */
    public function scanDue(string $environment, ?int $cmpId = null): array
    {
        $opened    = 0;
        $noticeDue = 0;

        foreach ($this->companiesWithCycles($environment, $cmpId) as $company) {
            $lead = $this->alertLeadDays($environment, $company);

            $st = $this->pdo->prepare(
                'UPDATE contract_renewals r
                 SET status = \'review_due\', updated_at = CURRENT_TIMESTAMP
                 FROM contracts c
                 WHERE c.id = r.contract_id
                   AND r.environment = :env AND r.cmp_id = :cmp
                   AND r.status = \'not_yet_due\'
                   AND c.archived_at IS NULL
                   AND c.status IN (\'active\', \'renewal_review\')
                   AND (
                        (r.decision_due_date IS NOT NULL AND r.decision_due_date <= CURRENT_DATE)
                     OR (r.notice_deadline IS NOT NULL AND r.notice_deadline <= CURRENT_DATE + make_interval(days => :lead))
                   )
                 RETURNING r.id, r.contract_id, r.cycle_no'
            );
            $st->execute(['env' => $environment, 'cmp' => $company, 'lead' => $lead]);

            foreach ($st->fetchAll() ?: [] as $row) {
                $opened++;
                $this->activity->recordSystem(
                    $environment,
                    $company,
                    (int) $row['contract_id'],
                    'renewal.review_due',
                    sprintf('Renewal cycle %d is due for review', (int) $row['cycle_no']),
                    ['renewal_id' => (int) $row['id'], 'lead_days' => $lead]
                );
            }

            $countSt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM contract_renewals r
                 JOIN contracts c ON c.id = r.contract_id
                 WHERE r.environment = ? AND r.cmp_id = ?
                   AND r.status IN (\'not_yet_due\', \'review_due\', \'under_review\')
                   AND c.archived_at IS NULL
                   AND r.notice_deadline IS NOT NULL
                   AND r.notice_deadline <= CURRENT_DATE + make_interval(days => ?)'
            );
            $countSt->execute([$environment, $company, $lead]);
            $noticeDue += (int) $countSt->fetchColumn();
        }

        return ['opened' => $opened, 'notice_due' => $noticeDue];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Extend the contract and open the cycle that follows.
     *
     * @param array<string,mixed> $renewal
     * @return array<string,mixed> the new cycle
     */
    private function renew(
        TenantContext $ctx,
        array $renewal,
        ?int $termMonths,
        ?string $proposedFrom,
        ?string $proposedTo
    ): array {
        $contractId = (int) $renewal['contract_id'];
        $contract   = $this->contractOrFail($ctx, $contractId);

        $currentExpiry = self::asDate($contract['expiry_date'] ?? null)
            ?? self::asDate($renewal['current_expiry'] ?? null);

        if ($currentExpiry === null) {
            throw DomainException::conflict(
                'This contract has no expiry date, so there is no term to extend.',
                'RENEWAL_NO_EXPIRY'
            );
        }

        $term      = $termMonths ?? $this->termMonthsFor($contract, $renewal);
        $newExpiry = $proposedTo ?? Dates::addMonths($currentExpiry, $term);
        if ($newExpiry === null || $newExpiry <= $currentExpiry) {
            throw DomainException::conflict(
                'The renewed term must end after the current expiry date.',
                'RENEWAL_TERM_INVALID'
            );
        }

        $noticeDays     = $contract['notice_period_days'] === null ? null : (int) $contract['notice_period_days'];
        $noticeDeadline = Dates::noticeDeadline($newExpiry, $noticeDays);

        // notice_deadline is derived from expiry, so moving the expiry without
        // moving it would leave the sweep counting down to a date that no
        // longer means anything.
        $this->pdo->prepare(
            'UPDATE contracts
             SET expiry_date = ?, notice_deadline = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$newExpiry, $noticeDeadline, $ctx->uuid, $contractId, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'contract', $contractId, 'contract.renewed', $contractId, [
            'expiry_date'     => ['from' => $currentExpiry, 'to' => $newExpiry],
            'notice_deadline' => ['from' => $contract['notice_deadline'], 'to' => $noticeDeadline],
        ]);
        $this->activity->record(
            $ctx,
            $contractId,
            'renewal.renewed',
            sprintf('Renewed to %s (%d months)', $newExpiry, $term),
            ['from' => $currentExpiry, 'to' => $newExpiry, 'term_months' => $term]
        );

        $contract['expiry_date']     = $newExpiry;
        $contract['notice_deadline'] = $noticeDeadline;

        $next = $this->openCycle($ctx, $contract, (int) $renewal['cycle_no'] + 1, $newExpiry, $term);
        if ($next === null) {
            // openCycle only returns null without an expiry date, and one was
            // just written above.
            throw new DomainException('The renewal was recorded but the next cycle could not be opened.', 'RENEWAL_FAILED', 500);
        }

        $this->pdo->prepare(
            'UPDATE contract_renewals SET proposed_start = COALESCE(proposed_start, ?), proposed_expiry = ?
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([
            $proposedFrom ?? Dates::addDays($currentExpiry, 1),
            $newExpiry,
            (int) $renewal['id'],
            $ctx->environment,
            $ctx->cmpId,
        ]);

        return $next;
    }

    /**
     * Insert one cycle.
     *
     * @param array<string,mixed> $contract
     * @return array<string,mixed>|null
     */
    private function openCycle(TenantContext $ctx, array $contract, int $cycleNo, string $expiry, int $termMonths): ?array
    {
        $noticeDays     = $contract['notice_period_days'] === null ? null : (int) $contract['notice_period_days'];
        $noticeDeadline = self::asDate($contract['notice_deadline'] ?? null) ?? Dates::noticeDeadline($expiry, $noticeDays);
        $decisionDue    = $this->decisionDueDate($ctx, $expiry, $noticeDeadline);

        $st = $this->pdo->prepare(
            'INSERT INTO contract_renewals
             (environment, cmp_id, contract_id, cycle_no, current_expiry, notice_deadline,
              decision_due_date, proposed_start, proposed_expiry, renewal_term_months,
              status, owner_uuid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'not_yet_due\', ?)
             RETURNING id'
        );
        $st->execute([
            $ctx->environment,
            $ctx->cmpId,
            (int) $contract['id'],
            $cycleNo,
            $expiry,
            $noticeDeadline,
            $decisionDue,
            Dates::addDays($expiry, 1),
            Dates::addMonths($expiry, $termMonths),
            $termMonths,
            $contract['owner_uuid'] ?? $ctx->uuid,
        ]);

        $id = (int) $st->fetchColumn();

        $this->audit->log($ctx, 'renewal', $id, 'renewal.cycle_opened', (int) $contract['id'], [
            'cycle_no'          => ['from' => null, 'to' => $cycleNo],
            'current_expiry'    => ['from' => null, 'to' => $expiry],
            'decision_due_date' => ['from' => null, 'to' => $decisionDue],
        ]);
        $this->activity->record(
            $ctx,
            (int) $contract['id'],
            'renewal.cycle_opened',
            sprintf('Renewal cycle %d opened, expiring %s', $cycleNo, $expiry),
            ['cycle_no' => $cycleNo, 'decision_due_date' => $decisionDue]
        );

        return $this->find($ctx, $id);
    }

    /**
     * When a decision has to exist by.
     *
     * The notice deadline where there is one — missing that date is what turns a
     * cancellable contract into an automatically renewed one, so it is a legal
     * fact, not a preference. Where there is no notice period the company's
     * widest alert rung stands in, which is the point the queue would have
     * raised it anyway.
     */
    private function decisionDueDate(TenantContext $ctx, string $expiry, ?string $noticeDeadline): ?string
    {
        if ($noticeDeadline !== null) {
            return $noticeDeadline;
        }

        return Dates::addDays($expiry, -$this->alertLeadDays($ctx->environment, $ctx->cmpId));
    }

    /** The widest rung of the company's expiry alert ladder. */
    private function alertLeadDays(string $environment, int $cmpId): int
    {
        $st = $this->pdo->prepare(
            'SELECT expiry_alert_days FROM contract_settings WHERE environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$environment, $cmpId]);
        $configured = $st->fetchColumn();

        $ladder = Dates::reminderLadder(
            is_string($configured) ? $configured : null,
            [90, 60, 30, 15, 7]
        );

        return $ladder[0] ?? 90;
    }

    /**
     * The term to renew for.
     *
     * The cycle's own term wins, then the contract's stated renewal frequency,
     * then the length of the term being renewed — a two-year agreement that says
     * nothing about its renewal is far more likely to renew for two years than
     * for a default twelve months.
     *
     * @param array<string,mixed>      $contract
     * @param array<string,mixed>|null $renewal
     */
    private function termMonthsFor(array $contract, ?array $renewal): int
    {
        if ($renewal !== null && $renewal['renewal_term_months'] !== null) {
            return (int) $renewal['renewal_term_months'];
        }

        $frequency = $contract['renewal_frequency'] ?? null;
        if (is_string($frequency)) {
            $months = Dates::frequencyMonths($frequency);
            if ($months !== null) {
                return $months;
            }
        }

        $start = self::asDate($contract['effective_date'] ?? null) ?? self::asDate($contract['commencement_date'] ?? null);
        $end   = self::asDate($contract['expiry_date'] ?? null);
        if ($start !== null && $end !== null) {
            $days = Dates::daysBetween($start, $end);
            if ($days !== null && $days >= 28) {
                // SMALLINT column, and a term longer than a human lifetime is a
                // data error rather than an agreement.
                return max(1, min(600, (int) round($days / 30.44)));
            }
        }

        return self::DEFAULT_TERM_MONTHS;
    }

    /**
     * @param array<string,mixed> $f
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function buildPipelineWhere(TenantContext $ctx, array $f): array
    {
        $clauses = ['r.environment = :env', 'r.cmp_id = :cmp', 'c.archived_at IS NULL'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        // The row-level half of RBAC, matching ContractService: without
        // view_all a user sees the renewals of contracts they own or run.
        if (! $ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            $clauses[]       = '(c.owner_uuid = :self OR c.created_by = :self2 OR r.owner_uuid = :self3)';
            $params['self']  = $ctx->uuid;
            $params['self2'] = $ctx->uuid;
            $params['self3'] = $ctx->uuid;
        }

        $status = Enums::coerce($f['status'] ?? null, Enums::RENEWAL_STATUSES);
        if ($status !== null) {
            $clauses[]         = 'r.status = :status';
            $params['status']  = $status;
        }

        $bucket = is_string($f['bucket'] ?? null) ? $f['bucket'] : 'all';
        $lead   = $this->alertLeadDays($ctx->environment, $ctx->cmpId);

        switch ($bucket) {
            case 'expiring_30':
            case 'expiring_60':
            case 'expiring_90':
                $clauses[]        = 'r.current_expiry IS NOT NULL
                                     AND r.current_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + make_interval(days => :window)';
                $params['window'] = (int) substr($bucket, strlen('expiring_'));
                break;

            case 'notice_due':
                $clauses[]      = 'r.notice_deadline IS NOT NULL
                                   AND r.notice_deadline <= CURRENT_DATE + make_interval(days => :lead)
                                   AND r.decision IS NULL';
                $params['lead'] = $lead;
                break;

            case 'auto_renewal_risk':
                // The expensive kind of miss: an auto-renewing contract whose
                // notice window is running out with nobody having decided. Past
                // deadlines stay in the bucket — that is precisely the case
                // somebody needs to see.
                $clauses[]      = 'c.auto_renewal = TRUE AND r.decision IS NULL
                                   AND r.notice_deadline IS NOT NULL
                                   AND r.notice_deadline <= CURRENT_DATE + make_interval(days => :lead)';
                $params['lead'] = $lead;
                break;

            case 'all':
            default:
                break;
        }

        if (! empty($f['owner_uuid'])) {
            $clauses[]        = '(r.owner_uuid = :owner OR c.owner_uuid = :owner2)';
            $params['owner']  = (string) $f['owner_uuid'];
            $params['owner2'] = (string) $f['owner_uuid'];
        }
        if (! empty($f['contract_type_id'])) {
            $clauses[]          = 'c.contract_type_id = :type_id';
            $params['type_id']  = (int) $f['contract_type_id'];
        }
        if (! empty($f['q'])) {
            $clauses[]       = '(c.title ILIKE :q OR c.contract_number ILIKE :q2 OR c.counterparty_name ILIKE :q3)';
            $params['q']     = '%' . $f['q'] . '%';
            $params['q2']    = '%' . $f['q'] . '%';
            $params['q3']    = '%' . $f['q'] . '%';
        }

        return ['WHERE ' . implode("\n  AND ", $clauses), $params];
    }

    /** @return list<int> */
    private function companiesWithCycles(string $environment, ?int $cmpId): array
    {
        if ($cmpId !== null) {
            return [$cmpId];
        }

        $st = $this->pdo->prepare(
            'SELECT DISTINCT cmp_id FROM contract_renewals WHERE environment = ? AND status = \'not_yet_due\''
        );
        $st->execute([$environment]);

        return array_map(static fn (array $r): int => (int) $r['cmp_id'], $st->fetchAll() ?: []);
    }

    /** @return array<string,mixed> @throws DomainException */
    private function contractOrFail(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, owner_uuid, status, effective_date, commencement_date, expiry_date,
                    notice_period_days, notice_deadline, renewal_type, renewal_frequency, auto_renewal
             FROM contracts
             WHERE id = ? AND environment = ? AND cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Contract not found.');
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function latestCycle(TenantContext $ctx, int $contractId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id FROM contract_renewals
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY cycle_no DESC LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $id = $st->fetchColumn();

        return $id === false ? null : $this->find($ctx, (int) $id);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        foreach (['id', 'cmp_id', 'contract_id', 'cycle_no', 'renewal_term_months', 'renewed_contract_id', 'notice_period_days', 'contract_type_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        foreach (['renegotiation_required', 'auto_renewal'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = ContractService::toBool($row[$key]);
            }
        }

        $row['days_to_decision'] = Dates::daysUntil($row['decision_due_date'] ?? null);
        $row['days_to_notice']   = Dates::daysUntil($row['notice_deadline'] ?? null);
        $row['days_to_expiry']   = Dates::daysUntil($row['current_expiry'] ?? null);

        return $row;
    }

    /** A DATE column arrives as `Y-m-d`; anything else is treated as absent. */
    private static function asDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return substr(trim($value), 0, 10);
    }
}
