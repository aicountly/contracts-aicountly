<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Response;
use App\Services\AuditService;
use App\Services\ContractService;
use App\Support\DomainException;
use App\Support\Permissions;
use App\Support\Validator;

/**
 * Cross-product links: the invoice, the order, the conversation that this
 * contract came from or produced.
 *
 * The link is a reference, never a copy — Contracts stores the other product's
 * code, record type and id and nothing else that could go stale. The queries
 * live in the controller because there is no logic behind them beyond tenant
 * scoping and one uniqueness rule; a service here would be a pass-through.
 */
final class LinkedRecordController extends BaseController
{
    /** Mirrors ck_linked_relationship. */
    private const RELATIONSHIPS = [
        'related', 'source', 'derived', 'invoice', 'payment', 'order', 'event',
        'conversation', 'document',
    ];

    public function index(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $contractId = $this->intId($id);
        $pdo        = $this->db();

        // Confirms the contract is this tenant's, and this caller's to see,
        // before listing what hangs off it.
        $this->run(fn () => (new ContractService($pdo))->findOrFail($ctx, $contractId));

        $st = $pdo->prepare(
            'SELECT id, contract_id, product_code, record_type, record_id, label,
                    relationship, url, metadata, linked_by, created_at
             FROM contract_linked_records
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY created_at DESC, id DESC'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        Response::success(array_map(self::hydrate(...), $st->fetchAll() ?: []));
    }

    public function store(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $contractId = $this->intId($id);

        $this->respond(function () use ($ctx, $contractId): array {
            $pdo = $this->db();
            (new ContractService($pdo))->findOrFail($ctx, $contractId);

            $v            = new Validator($this->body());
            $productCode  = $v->requiredString('product_code', 32);
            $recordType   = $v->requiredString('record_type', 48);
            $recordId     = $v->requiredString('record_id', 96);
            $label        = $v->optionalString('label', 255);
            $relationship = $v->optionalEnum('relationship', self::RELATIONSHIPS, 'related') ?? 'related';
            $url          = $v->optionalString('url', 512);
            $metadata     = $v->optionalObject('metadata');

            // A stored link is rendered as an anchor. Accepting any scheme
            // would let `javascript:` reach a colleague's click, so only the
            // two schemes a link to another product can legitimately use pass.
            if ($url !== null && ! preg_match('#^https?://#i', $url)) {
                $v->fail('url', 'Enter a link starting with http:// or https://.');
            }

            $v->assert();

            $st = $pdo->prepare(
                'INSERT INTO contract_linked_records
                 (environment, cmp_id, contract_id, product_code, record_type, record_id,
                  label, relationship, url, metadata, linked_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)
                 ON CONFLICT (contract_id, product_code, record_type, record_id) DO NOTHING
                 RETURNING *'
            );
            $st->execute([
                $ctx->environment, $ctx->cmpId, $contractId, $productCode, $recordType,
                $recordId, $label, $relationship, $url,
                json_encode($metadata, JSON_UNESCAPED_SLASHES), $ctx->uuid,
            ]);

            $row = $st->fetch();
            if (! is_array($row)) {
                // DO NOTHING rather than a constraint violation: the same
                // record linked twice is a double-click, and a 409 saying so is
                // more useful than a 500 from the unique index.
                throw DomainException::conflict('That record is already linked to this contract.', 'LINK_EXISTS');
            }

            (new AuditService($pdo))->log($ctx, 'linked_record', (int) $row['id'], 'link.created', $contractId, [
                'record' => ['from' => null, 'to' => $productCode . ':' . $recordType . ':' . $recordId],
            ]);

            return self::hydrate($row);
        }, 201);
    }

    public function destroy(?string $id = null): void
    {
        $ctx    = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $linkId = $this->intId($id);
        $pdo    = $this->db();

        $st = $pdo->prepare(
            'SELECT contract_id FROM contract_linked_records
             WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$linkId, $ctx->environment, $ctx->cmpId]);
        $contractId = $st->fetchColumn();

        if ($contractId === false) {
            Response::notFound('Link not found.');
        }

        // The link id is addressed directly, so the contract's own visibility
        // rules have to be asked here — otherwise walking link ids is a way to
        // edit a contract this caller cannot open.
        $this->run(fn () => (new ContractService($pdo))->findOrFail($ctx, (int) $contractId));

        $pdo->prepare(
            'DELETE FROM contract_linked_records WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$linkId, $ctx->environment, $ctx->cmpId]);

        (new AuditService($pdo))->log($ctx, 'linked_record', $linkId, 'link.deleted', (int) $contractId);

        Response::success(['deleted' => true]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        if (isset($row['metadata']) && is_string($row['metadata'])) {
            $row['metadata'] = json_decode($row['metadata'], true) ?: [];
        }

        foreach (['id', 'cmp_id', 'contract_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }
}
