<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which contracts a caller may see, as one SQL fragment.
 *
 * Tenant scope answers "whose data is this" and is applied everywhere by
 * environment and cmp_id. This answers the narrower question underneath it:
 * within one company, a user without CONTRACT_VIEW_ALL sees the contracts they
 * own, the ones they created, and the ones they have been asked to approve —
 * and nothing else.
 *
 * It lives here because it was written out longhand in six services and left
 * out of a seventh. SearchService's own comment predicted the consequence:
 * "three copies of this SQL would eventually not agree, and the one that
 * disagreed would be the leak." ObligationService::listOccurrences was that
 * copy — it scoped occurrences by tenant and never narrowed them at all, so a
 * read-only user was shown the number and title of every contract in the
 * company through the obligations register.
 *
 * A rule that decides who sees what belongs in one place, where a change is a
 * change everywhere and an omission is impossible rather than merely unlikely.
 *
 * Three narrowings in the codebase deliberately do NOT use this and should not
 * be collapsed into it: the renewal pipeline adds the renewal's own owner and
 * drops the approver branch, the audit-activity report narrows by actor rather
 * than by approver, and contract requests are narrowed by requester and
 * reviewer because a request is not a contract. Each answers a different
 * question; folding them in would be a merge of three rules into one that
 * matches none of them.
 */
final class ContractVisibility
{
    /**
     * The predicate, ready to append to a WHERE that already has a clause.
     *
     * Returns an empty fragment for a caller with CONTRACT_VIEW_ALL, so the
     * query planner sees no difference at all for the common case.
     *
     * `$alias` is the contracts table's alias in the calling query. `$prefix`
     * namespaces the placeholders: a query that embeds this twice, or that
     * already binds something called `vis`, needs them not to collide.
     *
     * `$leadingAnd` is false for callers that collect clauses into a list and
     * join them themselves, where a fragment carrying its own AND would produce
     * `AND AND`.
     *
     * @return array{0: string, 1: array<string,string>} the fragment and its binds
     */
    public static function predicate(
        TenantContext $ctx,
        string $alias = 'c',
        string $prefix = 'vis',
        bool $leadingAnd = true,
    ): array {
        if ($ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            return ['', []];
        }

        $self    = $prefix . '_self';
        $created = $prefix . '_created';
        $approve = $prefix . '_approver';

        $sql = ($leadingAnd ? ' AND ' : ' ') . "({$alias}.owner_uuid = :{$self}
                      OR {$alias}.created_by = :{$created}
                      OR EXISTS (
                          SELECT 1
                          FROM contract_approval_assignments a
                          JOIN contract_approval_instances i ON i.id = a.instance_id
                          WHERE i.contract_id = {$alias}.id AND a.approver_uuid = :{$approve}
                      ))";

        return [$sql, [
            $self    => $ctx->uuid,
            $created => $ctx->uuid,
            $approve => $ctx->uuid,
        ]];
    }

    /**
     * The same rule as a standalone EXISTS over a contract id held elsewhere.
     *
     * For queries that do not join `contracts` — an occurrence count, say —
     * where adding the join purely to narrow the result would change what the
     * query is about.
     *
     * @return array{0: string, 1: array<string,string>}
     */
    public static function existsFor(TenantContext $ctx, string $contractIdExpr, string $prefix = 'vis'): array
    {
        if ($ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            return ['', []];
        }

        $self    = $prefix . '_self';
        $created = $prefix . '_created';
        $approve = $prefix . '_approver';

        $sql = " AND EXISTS (
                    SELECT 1 FROM contracts vc
                    WHERE vc.id = {$contractIdExpr}
                      AND (vc.owner_uuid = :{$self}
                           OR vc.created_by = :{$created}
                           OR EXISTS (
                               SELECT 1
                               FROM contract_approval_assignments va
                               JOIN contract_approval_instances vi ON vi.id = va.instance_id
                               WHERE vi.contract_id = vc.id AND va.approver_uuid = :{$approve}
                           ))
                 )";

        return [$sql, [
            $self    => $ctx->uuid,
            $created => $ctx->uuid,
            $approve => $ctx->uuid,
        ]];
    }
}
