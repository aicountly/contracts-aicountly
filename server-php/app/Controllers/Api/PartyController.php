<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Contacts\ContactsClient;
use App\Services\PartyService;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\Validator;

/**
 * The parties to a contract, and the evidence of who they were.
 *
 * A party row points at the Contacts master by id and never copies it, so the
 * screen always shows the counterparty's current details. A contract, though,
 * is not a living record: renaming a company in Contacts must not silently
 * restate who signed an agreement two years ago. The snapshot endpoints are
 * what resolve that — they freeze the contact as it read on the day it
 * mattered, and rows they write are append-only.
 */
final class PartyController extends BaseController
{
    /**
     * Why a snapshot was taken.
     *
     * A vocabulary rather than free text: this is evidence, and a reason
     * somebody typed is not something a reviewer years later can filter,
     * count, or trust to mean the same thing twice.
     */
    private const SNAPSHOT_REASONS = [
        'execution', 'amendment', 'renewal', 'verification', 'correction', 'manual',
    ];

    public function index(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $contractId = $this->intId($id);

        $this->respond(fn () => $this->parties()->listForContract($ctx, $contractId));
    }

    public function store(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $contractId = $this->intId($id);

        $this->respond(
            fn () => $this->parties()->add($ctx, $contractId, $this->partyInput($this->body(), true)),
            201
        );
    }

    public function update(?string $id = null): void
    {
        $ctx     = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $partyId = $this->intId($id);

        $this->respond(fn () => $this->parties()->update($ctx, $partyId, $this->partyInput($this->body(), false)));
    }

    public function destroy(?string $id = null): void
    {
        $ctx     = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $partyId = $this->intId($id);

        $this->run(function () use ($ctx, $partyId): bool {
            $this->parties()->remove($ctx, $partyId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    public function snapshot(?string $id = null): void
    {
        $ctx     = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $partyId = $this->intId($id);
        $reason  = Enums::coerce($this->body()['reason'] ?? null, self::SNAPSHOT_REASONS, 'manual') ?? 'manual';

        $this->respond(fn () => $this->parties()->captureSnapshot($ctx, $partyId, $reason), 201);
    }

    /**
     * Freeze every party at once, which is what execution actually needs.
     *
     * Capturing them one call at a time would leave a contract half-evidenced
     * if the browser closed between requests, and the parties to an agreement
     * are only meaningful as a set.
     */
    public function snapshotAll(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $contractId = $this->intId($id);

        $this->respond(fn () => $this->parties()->captureAllForExecution($ctx, $contractId), 201);
    }

    public function snapshots(?string $id = null): void
    {
        $ctx     = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $partyId = $this->intId($id);

        $this->respond(fn () => $this->parties()->snapshots($ctx, $partyId));
    }

    /** Counterparty lookup, proxied to Contacts. */
    public function searchContacts(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $q = trim((string) (Request::query('q') ?? ''));

        // A type-ahead fires on every keystroke and each call leaves this
        // server for Contacts, so a one-character query is answered here.
        if (mb_strlen($q) < 2) {
            Response::success([]);
        }

        $this->rateLimit('counterparties.search', 90, 60);

        $rawLimit = Request::query('limit');
        $limit    = $rawLimit !== null && ctype_digit($rawLimit) ? min(max((int) $rawLimit, 1), 50) : 20;

        $this->respond(fn () => ContactsClient::search($ctx, mb_substr($q, 0, 120), $limit));
    }

    /**
     * The party fields this API accepts, normalised.
     *
     * On an update only the keys the caller actually sent come back, so a form
     * that posts one changed field cannot blank a signatory by omitting them.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function partyInput(array $body, bool $creating): array
    {
        $v   = new Validator($body);
        $out = [];

        if ($creating || $v->has('party_role')) {
            $out['party_role'] = $v->requiredEnum('party_role', Enums::PARTY_ROLES);
        }
        if ($creating || $v->has('display_name')) {
            $out['display_name'] = $v->requiredString('display_name', 255);
        }

        $optional = [
            'signatory_name'        => 160,
            'signatory_designation' => 160,
            'signatory_phone'       => 48,
            'contact_ref_id'        => 64,
            'contact_ref_type'      => 32,
        ];
        foreach ($optional as $field => $max) {
            if ($v->has($field)) {
                $out[$field] = $v->optionalString($field, $max);
            }
        }

        if ($v->has('signatory_email')) {
            $out['signatory_email'] = $v->optionalEmail('signatory_email');
        }
        if ($v->has('notes')) {
            $out['notes'] = $v->optionalText('notes', 4000);
        }
        if ($v->has('is_primary')) {
            $out['is_primary'] = $v->optionalBool('is_primary', false) ?? false;
        }

        $v->assert();

        return $out;
    }

    private function parties(): PartyService
    {
        return new PartyService($this->db());
    }
}
