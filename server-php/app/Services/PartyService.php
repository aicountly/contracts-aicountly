<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Modules\Contacts\ContactsClient;
use App\Modules\Manage\ManageClient;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\TenantContext;
use App\Support\Validator;
use PDO;

/**
 * Who the parties to a contract are, and the evidence of who they were.
 *
 * These are two different questions and the class answers them from two
 * different places. A party row carries a reference into Contacts and is
 * re-read live, so the screen always shows the counterparty's current details.
 * A snapshot is written from that live record at a moment that mattered — most
 * often execution — and is never touched again.
 *
 * That separation is the whole point. The Contacts master is a living record
 * and a contract is not: a company renamed in Contacts this morning must not
 * silently restate who signed an agreement two years ago. Rows in
 * `contract_party_snapshots` are therefore append-only, and this class offers
 * no way to change or remove one. A correction is a new snapshot with reason
 * `correction`, which leaves both readings visible and dated — which is what
 * makes it evidence rather than a claim.
 *
 * Every query filters `environment` AND `cmp_id` from the TenantContext.
 */
final class PartyService
{
    /**
     * Why a snapshot was taken.
     *
     * A vocabulary rather than free text, because this is evidence: a reason
     * somebody typed is not something a reviewer years later can filter, count,
     * or trust to mean the same thing twice. There is no CHECK constraint
     * behind it — the column takes any string — so the narrowing happens here.
     */
    public const SNAPSHOT_REASONS = ['execution', 'amendment', 'renewal', 'verification', 'correction', 'manual'];

    /** Fields whose change on a party row is worth an audit entry. */
    private const AUDITED_FIELDS = [
        'party_role', 'is_primary', 'contact_ref_id', 'contact_ref_type', 'display_name',
        'signatory_name', 'signatory_designation', 'signatory_email', 'signatory_phone', 'notes',
    ];

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
    // Reading
    // -----------------------------------------------------------------------

    /**
     * Every party to a contract, the company first and the primary
     * counterparty next — the order a signature block is read in.
     *
     * @return list<array<string,mixed>>
     */
    public function listForContract(TenantContext $ctx, int $contractId): array
    {
        $this->assertContract($ctx, $contractId);

        $st = $this->pdo->prepare(
            "SELECT * FROM contract_parties
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY (party_role = 'company') DESC, is_primary DESC, id"
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        return array_map(fn (array $r): array => $this->hydrate($r), $st->fetchAll() ?: []);
    }

    /**
     * One party, or null when it does not exist *for this tenant*.
     *
     * @return array<string,mixed>|null
     */
    public function find(TenantContext $ctx, int $partyId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_parties
             WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$partyId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findOrFail(TenantContext $ctx, int $partyId): array
    {
        $row = $this->find($ctx, $partyId);
        if ($row === null) {
            throw DomainException::notFound('Party not found.');
        }

        return $row;
    }

    /**
     * Every snapshot taken of a party, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function snapshots(TenantContext $ctx, int $partyId): array
    {
        $this->findOrFail($ctx, $partyId);

        $st = $this->pdo->prepare(
            'SELECT * FROM contract_party_snapshots
             WHERE party_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY captured_at DESC, id DESC'
        );
        $st->execute([$partyId, $ctx->environment, $ctx->cmpId]);

        return array_map(fn (array $r): array => $this->hydrateSnapshot($r), $st->fetchAll() ?: []);
    }

    /**
     * The snapshot that currently stands for a party.
     *
     * Ordered by id as well as time because a correction taken in the same
     * second as the row it corrects must still win, and a TIMESTAMP column
     * cannot separate them.
     *
     * @return array<string,mixed>|null
     */
    public function latestSnapshot(TenantContext $ctx, int $partyId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_party_snapshots
             WHERE party_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY captured_at DESC, id DESC LIMIT 1'
        );
        $st->execute([$partyId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrateSnapshot($row) : null;
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed> the created party
     */
    public function add(TenantContext $ctx, int $contractId, array $body): array
    {
        $this->assertContract($ctx, $contractId);

        $fields    = $this->readFields($body, true);
        $isPrimary = (bool) ($fields['is_primary'] ?? false);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $fields, $isPrimary): array {
            $st = $pdo->prepare(
                'INSERT INTO contract_parties
                 (contract_id, environment, cmp_id, party_role, is_primary,
                  contact_ref_id, contact_ref_type, display_name,
                  signatory_name, signatory_designation, signatory_email, signatory_phone, notes)
                 VALUES (:contract, :env, :cmp, :role, FALSE,
                         :ref_id, :ref_type, :name,
                         :sig_name, :sig_designation, :sig_email, :sig_phone, :notes)
                 RETURNING id'
            );
            $st->execute([
                'contract'        => $contractId,
                'env'             => $ctx->environment,
                'cmp'             => $ctx->cmpId,
                'role'            => $fields['party_role'],
                'ref_id'          => $fields['contact_ref_id'],
                'ref_type'        => $fields['contact_ref_type'],
                'name'            => $fields['display_name'],
                'sig_name'        => $fields['signatory_name'],
                'sig_designation' => $fields['signatory_designation'],
                'sig_email'       => $fields['signatory_email'],
                'sig_phone'       => $fields['signatory_phone'],
                'notes'           => $fields['notes'],
            ]);

            $id = (int) $st->fetchColumn();

            $this->audit->log($ctx, 'contract_party', $id, 'party.added', $contractId, [
                'party_role'   => ['from' => null, 'to' => $fields['party_role']],
                'display_name' => ['from' => null, 'to' => $fields['display_name']],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'party.added',
                sprintf('%s added as %s', $fields['display_name'], Enums::label($fields['party_role'])),
                ['party_id' => $id]
            );

            // Primary is set through the one path that also denormalises the
            // name onto the contract, so the repository list can never disagree
            // with the party table about who the counterparty is.
            if ($isPrimary) {
                return $this->setPrimaryCounterparty($ctx, $contractId, $id);
            }

            return $this->findOrFail($ctx, $id);
        });
    }

    /**
     * Change a party.
     *
     * Only the keys the caller actually sent are written, so a form posting one
     * field cannot blank a signatory by omitting them.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(TenantContext $ctx, int $partyId, array $body): array
    {
        $existing   = $this->findOrFail($ctx, $partyId);
        $contractId = (int) $existing['contract_id'];

        $fields = $this->readFields($body, false);

        // Refused rather than quietly stood down: the company cannot be its own
        // counterparty, and silently dropping the primary flag here would leave
        // the person who made the change believing the contract still names one.
        if ($existing['is_primary'] === true
            && ($fields['party_role'] ?? $existing['party_role']) === 'company'
            && ($fields['is_primary'] ?? true) === true) {
            throw DomainException::conflict(
                'This party is the primary counterparty. Choose another counterparty before making it the company.',
                'INVALID_PRIMARY_PARTY'
            );
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $partyId, $contractId, $existing, $fields): array {
            $columns = [
                'party_role', 'contact_ref_id', 'contact_ref_type', 'display_name',
                'signatory_name', 'signatory_designation', 'signatory_email', 'signatory_phone', 'notes',
            ];

            $set    = [];
            $params = [];
            foreach ($columns as $column) {
                if (! array_key_exists($column, $fields)) {
                    continue;
                }
                $set[]            = $column . ' = :' . $column;
                $params[$column]  = $fields[$column];
            }

            if ($set !== []) {
                $params['id']  = $partyId;
                $params['env'] = $ctx->environment;
                $params['cmp'] = $ctx->cmpId;

                $pdo->prepare(
                    'UPDATE contract_parties SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND environment = :env AND cmp_id = :cmp'
                )->execute($params);
            }

            if (array_key_exists('is_primary', $fields)) {
                if ($fields['is_primary'] === true) {
                    $this->setPrimaryCounterparty($ctx, $contractId, $partyId);
                } elseif ($existing['is_primary'] === true) {
                    // Standing down as primary takes the denormalised name with
                    // it. A contract left naming a counterparty that no party
                    // row claims is the drift this column exists to avoid.
                    $pdo->prepare(
                        'UPDATE contract_parties SET is_primary = FALSE, updated_at = CURRENT_TIMESTAMP
                         WHERE id = ? AND environment = ? AND cmp_id = ?'
                    )->execute([$partyId, $ctx->environment, $ctx->cmpId]);

                    $this->denormaliseCounterparty($ctx, $contractId, null);
                }
            }

            $updated = $this->findOrFail($ctx, $partyId);

            // The denormalised counterparty name follows a rename of whoever is
            // already primary. Leaving it behind would make the repository list
            // and the contract page disagree, and the list is what people
            // search.
            if ($updated['is_primary'] === true && $updated['display_name'] !== $existing['display_name']) {
                $this->denormaliseCounterparty($ctx, $contractId, (string) $updated['display_name']);
            }

            $this->audit->logChanges($ctx, 'contract_party', $partyId, $existing, $updated, self::AUDITED_FIELDS, $contractId, 'party.updated');
            $this->activity->record($ctx, $contractId, 'party.updated', sprintf('Party %s updated', $updated['display_name']), ['party_id' => $partyId]);

            return $updated;
        });
    }

    /**
     * Remove a party from a contract.
     *
     * Refused once the party has been snapshotted. The foreign key cascades, so
     * a delete here would take the evidence of who signed with it — and a
     * contract that can lose its own signatories on somebody's tidy-up is not a
     * record anyone can rely on. A party who should not have been there is an
     * amendment, not a deletion.
     */
    public function remove(TenantContext $ctx, int $partyId): void
    {
        $existing   = $this->findOrFail($ctx, $partyId);
        $contractId = (int) $existing['contract_id'];

        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM contract_party_snapshots
             WHERE party_id = ? AND environment = ? AND cmp_id = ?'
        );
        $st->execute([$partyId, $ctx->environment, $ctx->cmpId]);

        if ((int) $st->fetchColumn() > 0) {
            throw DomainException::conflict(
                'This party has been captured on the contract and cannot be removed. Record an amendment instead.',
                'PARTY_SNAPSHOTTED'
            );
        }

        Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $partyId, $contractId, $existing): void {
            // Audited before the delete: afterwards there is no row to reference
            // and the audit table is append-only, so the record survives.
            $this->audit->log($ctx, 'contract_party', $partyId, 'party.removed', $contractId, [
                'display_name' => ['from' => $existing['display_name'], 'to' => null],
                'party_role'   => ['from' => $existing['party_role'], 'to' => null],
            ]);

            $pdo->prepare('DELETE FROM contract_parties WHERE id = ? AND environment = ? AND cmp_id = ?')
                ->execute([$partyId, $ctx->environment, $ctx->cmpId]);

            // The denormalised name described the row that just went; leaving it
            // would have the repository list naming a counterparty the contract
            // no longer has.
            if ($existing['is_primary'] === true) {
                $this->denormaliseCounterparty($ctx, $contractId, null);
            }

            $this->activity->record($ctx, $contractId, 'party.removed', sprintf('%s removed as a party', $existing['display_name']));
        });
    }

    /**
     * Make one party the contract's primary counterparty.
     *
     * Also writes the party's name onto `contracts.counterparty_name`. That
     * duplication is deliberate: the repository list, its counterparty filter
     * and its full-text search all read that column, and answering them through
     * a join to the primary party row would cost a join and a sort on every
     * page of every list in the product.
     *
     * @return array<string,mixed> the party, now primary
     */
    public function setPrimaryCounterparty(TenantContext $ctx, int $contractId, int $partyId): array
    {
        $this->assertContract($ctx, $contractId);
        $party = $this->findOrFail($ctx, $partyId);

        if ((int) $party['contract_id'] !== $contractId) {
            throw DomainException::notFound('Party not found.');
        }

        // The company is one side of its own agreement, never the other. Naming
        // it as the counterparty would put the company's own name in every
        // repository row and quietly break every counterparty report.
        if ((string) $party['party_role'] === 'company') {
            throw DomainException::conflict(
                'The company is a party to this contract, not its counterparty.',
                'INVALID_PRIMARY_PARTY'
            );
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $partyId, $party): array {
            // Cleared for the whole contract first: "primary" is a property of
            // the contract, and two rows claiming it is a state no reader could
            // resolve.
            $pdo->prepare(
                'UPDATE contract_parties SET is_primary = FALSE, updated_at = CURRENT_TIMESTAMP
                 WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND id <> ? AND is_primary'
            )->execute([$contractId, $ctx->environment, $ctx->cmpId, $partyId]);

            $pdo->prepare(
                'UPDATE contract_parties SET is_primary = TRUE, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$partyId, $ctx->environment, $ctx->cmpId]);

            $this->denormaliseCounterparty($ctx, $contractId, (string) $party['display_name']);

            $this->audit->log($ctx, 'contract_party', $partyId, 'party.primary_set', $contractId, [
                'counterparty_name' => ['from' => null, 'to' => $party['display_name']],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'party.primary_set',
                sprintf('%s set as the primary counterparty', $party['display_name']),
                ['party_id' => $partyId]
            );

            return $this->findOrFail($ctx, $partyId);
        });
    }

    // -----------------------------------------------------------------------
    // Snapshots — the legally load-bearing part
    // -----------------------------------------------------------------------

    /**
     * Freeze a party as the master reads it right now.
     *
     * The live contact is read from Contacts and written into an immutable row.
     * Nothing updates a snapshot afterwards, here or anywhere else: a later
     * capture is a new row, and the two of them together are the record of what
     * changed and when it was noticed.
     *
     * Contacts being unreachable is a refusal, not a degradation. A snapshot
     * assembled from an empty reply would say the counterparty had no address
     * and no registration on the day it was signed, and that is a fabricated
     * legal record — far worse than a failed request somebody can retry.
     *
     * @return array<string,mixed> the snapshot written
     */
    public function captureSnapshot(TenantContext $ctx, int $partyId, string $reason): array
    {
        $party  = $this->findOrFail($ctx, $partyId);
        $reason = Enums::coerce($reason, self::SNAPSHOT_REASONS, 'manual') ?? 'manual';

        return $this->writeSnapshot($ctx, $party, $this->sourceFor($ctx, $party), $reason);
    }

    /**
     * Freeze every party to a contract at once.
     *
     * Called at execution, and one call rather than one per party because the
     * parties to an agreement are only meaningful as a set: a contract half
     * evidenced because a browser closed between requests is worse than one
     * not evidenced at all, since it looks complete.
     *
     * The company's own side comes from Manage, not Contacts — Manage is the
     * company master and the company is not in anybody's address book. A
     * contract with no company party gets one here, because the signature block
     * of an executed agreement has two sides whether or not somebody filled the
     * form in fully.
     *
     * @return list<array<string,mixed>> the snapshots written
     */
    public function captureAllForExecution(TenantContext $ctx, int $contractId): array
    {
        $this->assertContract($ctx, $contractId);

        $company = $this->companySummary($ctx);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $company): array {
            $parties = $this->listForContract($ctx, $contractId);

            $hasCompany = false;
            foreach ($parties as $party) {
                if ((string) $party['party_role'] === 'company') {
                    $hasCompany = true;
                    break;
                }
            }

            if (! $hasCompany) {
                $created = $this->add($ctx, $contractId, [
                    'party_role'   => 'company',
                    'display_name' => $company['legal_name'] !== '' ? $company['legal_name'] : $ctx->companyName(),
                ]);
                array_unshift($parties, $created);
            }

            $out = [];
            foreach ($parties as $party) {
                $source = (string) $party['party_role'] === 'company'
                    ? self::fromCompany($company)
                    : $this->sourceFor($ctx, $party);

                $out[] = $this->writeSnapshot($ctx, $party, $source, 'execution');
            }

            $this->activity->record(
                $ctx,
                $contractId,
                'party.snapshot.execution',
                sprintf('Captured %d part%s as executed', count($out), count($out) === 1 ? 'y' : 'ies')
            );

            return $out;
        });
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * What a party looked like at this moment, in the snapshot's own vocabulary.
     *
     * A party linked to Contacts is read from Contacts. One recorded by hand
     * has no master to read, so its own row is the best record there is and is
     * captured as such — refusing to evidence a hand-entered party would leave
     * the commonest small-contract case unrecordable.
     *
     * @param array<string,mixed> $party
     * @return array{fields: array<string,string>, raw: array<string,mixed>}
     */
    private function sourceFor(TenantContext $ctx, array $party): array
    {
        $contactId = (string) ($party['contact_ref_id'] ?? '');

        if ($contactId !== '') {
            $contact = ContactsClient::find($ctx, $contactId);
            if ($contact !== null) {
                return [
                    'fields' => [
                        'legal_name'         => (string) $contact['legal_name'],
                        'trading_name'       => (string) $contact['trading_name'],
                        'registered_address' => (string) $contact['registered_address'],
                        'gstin'              => (string) $contact['gstin'],
                        'pan'                => (string) $contact['pan'],
                        'cin'                => (string) $contact['cin'],
                        'email'              => (string) $contact['email'],
                        'phone'              => (string) $contact['phone'],
                    ],
                    'raw' => ['source' => 'contacts', 'contact' => $contact],
                ];
            }
        }

        return [
            'fields' => [
                'legal_name'         => (string) $party['display_name'],
                'trading_name'       => '',
                'registered_address' => '',
                'gstin'              => '',
                'pan'                => '',
                'cin'                => '',
                'email'              => (string) ($party['signatory_email'] ?? ''),
                'phone'              => (string) ($party['signatory_phone'] ?? ''),
            ],
            // Recorded rather than inferred: a reader years later must be able
            // to tell a party the master confirmed from one typed into a form,
            // and from one whose contact had already been deleted.
            'raw' => [
                'source'         => $contactId === '' ? 'party_row' : 'party_row_contact_missing',
                'contact_ref_id' => $contactId === '' ? null : $contactId,
                'party'          => [
                    'display_name'   => $party['display_name'],
                    'party_role'     => $party['party_role'],
                    'signatory_name' => $party['signatory_name'],
                ],
            ],
        ];
    }

    /**
     * The company's own side, from Manage.
     *
     * @param array<string,string> $company
     * @return array{fields: array<string,string>, raw: array<string,mixed>}
     */
    private static function fromCompany(array $company): array
    {
        $address = implode(', ', array_filter([
            $company['address'],
            $company['city'],
            $company['state'],
            $company['pincode'],
            $company['country'],
        ], static fn (string $part): bool => trim($part) !== ''));

        return [
            'fields' => [
                'legal_name'         => $company['legal_name'],
                'trading_name'       => $company['trading_name'],
                'registered_address' => $address,
                'gstin'              => $company['gstin'],
                'pan'                => $company['pan'],
                'cin'                => $company['cin'],
                'email'              => $company['email'],
                'phone'              => $company['phone'],
            ],
            'raw' => ['source' => 'manage', 'company' => $company],
        ];
    }

    /**
     * The company as Manage describes it now.
     *
     * The context already carries the payload the request was authorised with,
     * so this is normally free. It is fetched only when a caller built a
     * context without one, and a company that cannot be read at all stops the
     * capture rather than letting it write a nameless company into evidence.
     *
     * @return array<string,string>
     */
    private function companySummary(TenantContext $ctx): array
    {
        $company = $ctx->company ?? ManageClient::companyInfo($ctx->sesKey, (string) $ctx->cmpId);

        if ($company === null) {
            throw DomainException::unavailable('The company record could not be read from Manage.');
        }

        return ManageClient::summarise($company);
    }

    /**
     * Write one immutable snapshot row.
     *
     * @param array<string,mixed>                                        $party
     * @param array{fields: array<string,string>, raw: array<string,mixed>} $source
     * @return array<string,mixed>
     */
    private function writeSnapshot(TenantContext $ctx, array $party, array $source, string $reason): array
    {
        $fields  = $source['fields'];
        $partyId = (int) $party['id'];

        $st = $this->pdo->prepare(
            'INSERT INTO contract_party_snapshots
             (party_id, contract_id, environment, cmp_id, captured_reason,
              legal_name, trading_name, registered_address, gstin, pan, cin, email, phone,
              authorised_representative, representative_designation, raw_payload, captured_by)
             VALUES (:party, :contract, :env, :cmp, :reason,
                     :legal, :trading, :address, :gstin, :pan, :cin, :email, :phone,
                     :rep, :rep_designation, :raw::jsonb, :actor)
             RETURNING id'
        );
        $st->execute([
            'party'           => $partyId,
            'contract'        => (int) $party['contract_id'],
            'env'             => $ctx->environment,
            'cmp'             => $ctx->cmpId,
            'reason'          => $reason,
            'legal'           => self::nullIfBlank($fields['legal_name']),
            'trading'         => self::nullIfBlank($fields['trading_name']),
            'address'         => self::nullIfBlank($fields['registered_address']),
            'gstin'           => self::nullIfBlank($fields['gstin']),
            'pan'             => self::nullIfBlank($fields['pan']),
            'cin'             => self::nullIfBlank($fields['cin']),
            'email'           => self::nullIfBlank($fields['email']),
            'phone'           => self::nullIfBlank($fields['phone']),
            // The signatory is the party row's, not the master's. Who signed is
            // a fact about this agreement; Contacts has no opinion on it.
            'rep'             => $party['signatory_name'],
            'rep_designation' => $party['signatory_designation'],
            'raw'             => json_encode($source['raw'], JSON_UNESCAPED_SLASHES),
            'actor'           => $ctx->uuid,
        ]);

        $id = (int) $st->fetchColumn();

        $this->audit->log($ctx, 'contract_party_snapshot', $id, 'party.snapshot.' . $reason, (int) $party['contract_id'], [
            'party_id'   => ['from' => null, 'to' => $partyId],
            'legal_name' => ['from' => null, 'to' => $fields['legal_name']],
        ]);

        $row = $this->snapshotById($ctx, $id);
        if ($row === null) {
            // Only reachable if the row vanished between INSERT and SELECT in
            // one transaction, which would mean something far worse than a
            // failed capture.
            throw new DomainException('The snapshot was written but could not be read back.', 'SNAPSHOT_FAILED', 500);
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function snapshotById(TenantContext $ctx, int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_party_snapshots
             WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrateSnapshot($row) : null;
    }

    private function denormaliseCounterparty(TenantContext $ctx, int $contractId, ?string $name): void
    {
        $this->pdo->prepare(
            'UPDATE contracts SET counterparty_name = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$name, $ctx->uuid, $contractId, $ctx->environment, $ctx->cmpId]);
    }

    /**
     * The contract exists for this tenant.
     *
     * Checked before every party operation and by id only — a party is reached
     * through a contract, and a caller walking contract ids must not be able to
     * tell another company's contract from one that was never created.
     */
    private function assertContract(TenantContext $ctx, int $contractId): void
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw DomainException::notFound('Contract not found.');
        }
    }

    /**
     * The party fields this service accepts.
     *
     * On an update only the keys the caller sent are returned, which is what
     * lets update() write a partial change without blanking everything else.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function readFields(array $body, bool $creating): array
    {
        $v   = new Validator($body);
        $out = [];

        if ($creating || $v->has('party_role')) {
            $out['party_role'] = $v->requiredEnum('party_role', Enums::PARTY_ROLES);
        }
        if ($creating || $v->has('display_name')) {
            $out['display_name'] = $v->requiredString('display_name', 255);
        }

        foreach (['signatory_name' => 160, 'signatory_designation' => 160, 'signatory_phone' => 48, 'contact_ref_id' => 64] as $field => $max) {
            if ($creating || $v->has($field)) {
                $out[$field] = $v->optionalString($field, $max);
            }
        }

        if ($creating || $v->has('signatory_email')) {
            $out['signatory_email'] = $v->optionalEmail('signatory_email');
        }
        if ($creating || $v->has('notes')) {
            $out['notes'] = $v->optionalText('notes', 4000);
        }
        if ($v->has('is_primary')) {
            $out['is_primary'] = $v->optionalBool('is_primary', false) ?? false;
        }

        // The reference type only means anything alongside a reference, and
        // defaulting it saves every caller from repeating the only value
        // Contacts can currently be.
        if (array_key_exists('contact_ref_id', $out)) {
            $out['contact_ref_type'] = $out['contact_ref_id'] === null
                ? null
                : ($v->optionalString('contact_ref_type', 32) ?? 'contact');
        } elseif ($v->has('contact_ref_type')) {
            $out['contact_ref_type'] = $v->optionalString('contact_ref_type', 32);
        }

        $v->assert();

        return $out;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        $row['is_primary'] = ContractService::toBool($row['is_primary'] ?? false);

        foreach (['id', 'contract_id', 'cmp_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateSnapshot(array $row): array
    {
        foreach (['id', 'party_id', 'contract_id', 'cmp_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        if (isset($row['raw_payload']) && is_string($row['raw_payload'])) {
            $decoded            = json_decode($row['raw_payload'], true);
            $row['raw_payload'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    /**
     * NULL rather than '' for an absent value.
     *
     * A snapshot column holding an empty string reads as "we captured this and
     * it was blank"; NULL reads as "the master had nothing here". Only one of
     * those is true and the difference matters to whoever reads the row.
     */
    private static function nullIfBlank(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
