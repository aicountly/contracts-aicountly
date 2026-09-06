<?php

declare(strict_types=1);

/**
 * Development and demo data.
 *
 *   php database/seed.php --company=1 [--env=sandbox] [--reset]
 *
 * Five realistic contracts with parties, commercial terms, clauses,
 * obligations, milestones, an approval, a renewal cycle and a risk assessment —
 * enough to make every screen show something real while it is being built.
 *
 * REFUSES TO RUN AGAINST PRODUCTION. This writes rows that look like business
 * records and are not, and a demo contract sitting in a real company's renewal
 * queue is worse than an empty screen. The guard is on APP_ENV rather than a
 * flag, because a flag is exactly what gets copied along with the command.
 */

$root = dirname(__DIR__);

$vendorAutoload = $root . '/vendor/autoload.php';
if (is_readable($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    require_once $root . '/app/Core/Autoloader.php';
    \App\Core\Autoloader::register($root . '/app');
}

use App\Core\Database;
use App\Core\Env;
use App\Services\ApprovalService;
use App\Services\CompanyBootstrapService;
use App\Services\ContractService;
use App\Services\MilestoneService;
use App\Services\ObligationService;
use App\Services\RiskEngine;
use App\Support\Dates;
use App\Support\Environment;
use App\Support\Permissions;
use App\Support\TenantContext;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

Env::setApiRoot($root);

$args    = array_slice($argv, 1);
$cmpId   = 1;
$envName = Environment::resolve();
$reset   = in_array('--reset', $args, true);

foreach ($args as $arg) {
    if (str_starts_with($arg, '--company=')) {
        $value = substr($arg, strlen('--company='));
        $cmpId = ctype_digit($value) ? (int) $value : 1;
    }
    if (str_starts_with($arg, '--env=')) {
        $value = strtolower(substr($arg, strlen('--env=')));
        if (in_array($value, ['production', 'sandbox'], true)) {
            $envName = $value;
        }
    }
}

if ($envName === Environment::PRODUCTION) {
    fwrite(STDERR, "Refusing to seed demo data into production.\n");
    fwrite(STDERR, "These rows look like business records and are not.\n");
    exit(1);
}

$pdo = Database::pdo();
if ($pdo === null) {
    fwrite(STDERR, "Cannot connect to the database.\n" . Database::unavailableMessage() . "\n");
    exit(1);
}

$owner = 'DEMO-OWNER-UUID';
$ctx   = new TenantContext(
    uuid: $owner,
    sesKey: 'seed',
    cmpId: $cmpId,
    fyId: 1,
    boId: 1,
    environment: $envName,
    company: ['cmp_id' => $cmpId, 'legal_name' => 'Demo Company', 'currency' => 'INR'],
    permissions: Permissions::all(),
    roles: ['contract_admin'],
);

if ($reset) {
    // Only the demo rows, identified by the marker written into every one of
    // them. A blanket TRUNCATE would take a developer's own work with it.
    $pdo->prepare(
        "DELETE FROM contracts
         WHERE environment = ? AND cmp_id = ? AND metadata->>'seeded' = 'true'"
    )->execute([$envName, $cmpId]);
    echo "Removed previously seeded contracts.\n";
}

(new CompanyBootstrapService($pdo))->ensure($envName, $cmpId, 'INR');
echo "Company {$cmpId} configuration ready.\n";

$typeId = static function (string $code) use ($pdo, $envName, $cmpId): ?int {
    $st = $pdo->prepare('SELECT id FROM contract_types WHERE environment = ? AND cmp_id = ? AND code = ?');
    $st->execute([$envName, $cmpId, $code]);
    $id = $st->fetchColumn();

    return $id === false ? null : (int) $id;
};

$today = Dates::today();

/** @var list<array<string,mixed>> */
$blueprints = [
    [
        'type'         => 'saas',
        'title'        => 'SaaS Subscription — Northwind Analytics',
        'counterparty' => 'Northwind Analytics Pvt Ltd',
        'status'       => 'active',
        'effective'    => Dates::addMonths($today, -10),
        'expiry'       => Dates::addMonths($today, 2),
        'notice'       => 60,
        'auto_renew'   => true,
        'value'        => '840000.00',
        'direction'    => 'payable',
        'law'          => 'India',
        'summary'      => 'Annual subscription for 120 seats, billed quarterly in advance.',
        // A well-drafted agreement: the standard clause set, nothing missing.
        'clauses'      => 'standard',
        'obligations'  => [
            ['title' => 'Quarterly service review', 'freq' => 'quarterly', 'party' => 'counterparty', 'evidence' => true],
            ['title' => 'Quarterly subscription payment', 'freq' => 'quarterly', 'party' => 'company', 'amount' => '210000.00'],
        ],
        'milestones'   => [
            ['title' => 'Renewal decision deadline', 'in_months' => 0, 'type' => 'renewal_decision'],
        ],
    ],
    [
        'type'         => 'vendor_agreement',
        'title'        => 'Facilities Management — Sterling Services',
        'counterparty' => 'Sterling Facility Services LLP',
        'status'       => 'active',
        'effective'    => Dates::addMonths($today, -18),
        'expiry'       => Dates::addMonths($today, 18),
        'notice'       => 90,
        'auto_renew'   => false,
        'value'        => '2400000.00',
        'direction'    => 'payable',
        'law'          => 'India',
        'summary'      => 'Housekeeping and maintenance across two sites, billed monthly.',
        'clauses'      => 'standard',
        'obligations'  => [
            ['title' => 'Monthly invoice and service report', 'freq' => 'monthly', 'party' => 'counterparty', 'evidence' => true],
            ['title' => 'Annual insurance certificate', 'freq' => 'annual', 'party' => 'counterparty', 'evidence' => true],
        ],
        'milestones'   => [],
    ],
    [
        'type'         => 'customer_agreement',
        'title'        => 'Master Services Agreement — Harbour Retail',
        'counterparty' => 'Harbour Retail Group Ltd',
        'status'       => 'active',
        'effective'    => Dates::addMonths($today, -6),
        'expiry'       => Dates::addMonths($today, 30),
        'notice'       => 30,
        'auto_renew'   => false,
        'value'        => '5600000.00',
        'direction'    => 'receivable',
        'law'          => 'India',
        'summary'      => 'Implementation and support, milestone-billed against an agreed plan.',
        // The demonstration of what the risk engine is for: everything else is
        // in order, and the liability clause is unlimited.
        'clauses'      => 'unlimited_liability',
        'obligations'  => [
            ['title' => 'Monthly progress report', 'freq' => 'monthly', 'party' => 'company', 'evidence' => true],
            ['title' => 'Quarterly steering committee', 'freq' => 'quarterly', 'party' => 'both'],
        ],
        'milestones'   => [
            ['title' => 'Phase 1 acceptance', 'in_months' => -2, 'type' => 'acceptance', 'amount' => '1400000.00'],
            ['title' => 'Phase 2 go-live', 'in_months' => 3, 'type' => 'go_live', 'amount' => '2100000.00'],
        ],
    ],
    [
        'type'         => 'nda',
        'title'        => 'Mutual NDA — Pinecrest Capital',
        'counterparty' => 'Pinecrest Capital Advisors',
        'status'       => 'active',
        'effective'    => Dates::addMonths($today, -3),
        'expiry'       => Dates::addMonths($today, 21),
        'notice'       => null,
        'auto_renew'   => false,
        'value'        => null,
        'direction'    => 'none',
        'law'          => 'India',
        'summary'      => 'Mutual confidentiality for a prospective transaction. Survives three years.',
        'clauses'      => 'nda',
        'obligations'  => [],
        'milestones'   => [],
    ],
    [
        'type'         => 'lease',
        'title'        => 'Office Lease — Unit 4, Meridian Park',
        'counterparty' => 'Meridian Estates Pvt Ltd',
        'status'       => 'draft',
        'effective'    => Dates::addMonths($today, 1),
        'expiry'       => Dates::addMonths($today, 37),
        'notice'       => 180,
        'auto_renew'   => true,
        'value'        => '7200000.00',
        'direction'    => 'payable',
        'law'          => 'India',
        // Deliberately thin: an uploaded contract nobody has analysed yet, so
        // the review queue and the "missing protections" findings have
        // something real to show.
        'summary'      => 'Three-year lease with a six-month notice period and an annual escalation of 5%.',
        'clauses'      => 'sparse',
        'obligations'  => [
            ['title' => 'Monthly rent', 'freq' => 'monthly', 'party' => 'company', 'amount' => '200000.00'],
        ],
        'milestones'   => [
            ['title' => 'Fit-out completion', 'in_months' => 2, 'type' => 'delivery'],
        ],
    ],
];


/**
 * Attach clauses to a seeded contract, from the company's own library.
 *
 * A contract record with no clauses fires every "missing protection" rule, so
 * without this every demo contract reads critical and the risk screen shows one
 * flat colour. Real demo data has to be able to look healthy, or the thing it is
 * demonstrating cannot be seen.
 */
function seed_clauses(PDO $pdo, string $env, int $cmpId, int $contractId, string $profile): void
{
    $standard = [
        'confidentiality', 'limitation_liability', 'termination', 'termination_convenience',
        'termination_cause', 'payment_terms', 'indemnity', 'intellectual_property',
        'data_protection', 'force_majeure', 'governing_law', 'dispute_resolution',
        'renewal', 'assignment', 'insurance', 'survival', 'notices', 'sla',
    ];

    $categories = match ($profile) {
        'standard'            => $standard,
        'unlimited_liability' => $standard,
        'nda'                 => ['confidentiality', 'governing_law', 'dispute_resolution', 'survival', 'notices', 'termination'],
        'sparse'              => ['payment_terms', 'termination', 'governing_law'],
        default               => $standard,
    };

    $lookup = $pdo->prepare(
        'SELECT cl.id, cl.name, cl.standard_text, cc.id AS category_id
         FROM clause_library cl
         JOIN clause_categories cc ON cc.id = cl.category_id
         WHERE cl.environment = ? AND cl.cmp_id = ? AND cc.code = ?
         ORDER BY cl.id LIMIT 1'
    );

    $insert = $pdo->prepare(
        'INSERT INTO contract_clauses
         (environment, cmp_id, contract_id, category_id, library_clause_id, clause_number,
          heading, body_text, is_ai_extracted, verification_state)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, FALSE, \'human_verified\')'
    );

    $number = 1;
    foreach ($categories as $code) {
        $lookup->execute([$env, $cmpId, $code]);
        $clause = $lookup->fetch();
        if (! is_array($clause)) {
            continue;
        }

        $body = (string) $clause['standard_text'];

        // One contract carries an unlimited liability clause, because a risk
        // screen with nothing on it demonstrates nothing.
        if ($profile === 'unlimited_liability' && $code === 'limitation_liability') {
            $body = 'The Supplier shall have unlimited liability for any loss or damage arising '
                . 'out of or in connection with this Agreement, howsoever caused.';
        }

        $insert->execute([
            $env, $cmpId, $contractId, (int) $clause['category_id'], (int) $clause['id'],
            (string) $number, (string) $clause['name'], $body,
        ]);
        $number++;
    }
}

/**
 * A document row so a seeded contract does not read as "no agreement attached".
 *
 * There are no bytes behind it — Drive is not involved in seeding — so the
 * version is marked local with a path that does not exist. That is honest: the
 * record says a document is expected, and any attempt to download it will fail
 * loudly rather than return something fabricated.
 */
function seed_document(PDO $pdo, string $env, int $cmpId, int $contractId, string $title): void
{
    $st = $pdo->prepare(
        'INSERT INTO contract_documents (environment, cmp_id, contract_id, doc_kind, title, version_count)
         VALUES (?, ?, ?, \'contract\', ?, 1) RETURNING id'
    );
    $st->execute([$env, $cmpId, $contractId, $title]);
    $documentId = (int) $st->fetchColumn();

    $version = $pdo->prepare(
        'INSERT INTO contract_document_versions
         (document_id, environment, cmp_id, version_no, version_status, filename, content_type,
          size_bytes, storage_provider, local_path, is_executed, uploaded_by)
         VALUES (?, ?, ?, 1, \'executed\', ?, \'application/pdf\', 0, \'local\', ?, TRUE, \'DEMO-OWNER-UUID\')
         RETURNING id'
    );
    $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($title)) ?? 'contract';
    $version->execute([$documentId, $env, $cmpId, $slug . '.pdf', 'seed://' . $slug . '.pdf']);

    $pdo->prepare('UPDATE contract_documents SET current_version_id = ?, is_executed_copy = TRUE WHERE id = ?')
        ->execute([(int) $version->fetchColumn(), $documentId]);
}

$contracts   = new ContractService($pdo);
$obligations = new ObligationService($pdo);
$milestones  = new MilestoneService($pdo);
$risk        = new RiskEngine($pdo);

$created = 0;

foreach ($blueprints as $bp) {
    $contract = $contracts->create($ctx, [
        'title'              => $bp['title'],
        'contract_type_id'   => $typeId($bp['type']),
        'counterparty_name'  => $bp['counterparty'],
        'source'             => 'uploaded',
        'effective_date'     => $bp['effective'],
        'expiry_date'        => $bp['expiry'],
        'notice_period_days' => $bp['notice'],
        'auto_renewal'       => $bp['auto_renew'],
        'renewal_type'       => $bp['auto_renew'] ? 'auto_renew' : 'fixed_term',
        'renewal_frequency'  => 'annual',
        'currency'           => 'INR',
        'total_value'        => $bp['value'],
        'governing_law'      => $bp['law'],
        'jurisdiction'       => 'Mumbai',
        'commercial_summary' => $bp['summary'],
        'owner_uuid'         => $owner,
    ]);

    $contractId = (int) $contract['id'];

    // The marker is what makes --reset able to remove demo rows without taking
    // a developer's own contracts with them.
    $pdo->prepare("UPDATE contracts SET metadata = '{\"seeded\":true}'::jsonb WHERE id = ?")
        ->execute([$contractId]);

    $pdo->prepare(
        'INSERT INTO contract_parties
         (contract_id, environment, cmp_id, party_role, is_primary, display_name, signatory_name, signatory_designation)
         VALUES (?, ?, ?, ?, TRUE, ?, ?, ?)'
    )->execute([
        $contractId, $envName, $cmpId, 'counterparty', $bp['counterparty'],
        'A. Signatory', 'Authorised Signatory',
    ]);

    $pdo->prepare(
        'INSERT INTO contract_commercial_terms
         (environment, cmp_id, contract_id, currency, total_value, value_direction,
          payment_terms_days, billing_frequency)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT (contract_id) DO NOTHING'
    )->execute([$envName, $cmpId, $contractId, 'INR', $bp['value'], $bp['direction'], 30, 'monthly']);

    foreach ($bp['obligations'] as $index => $obligation) {
        $obligations->create($ctx, $contractId, [
            'title'             => $obligation['title'],
            'responsible_party' => $obligation['party'],
            'owner_uuid'        => $owner,
            'frequency'         => $obligation['freq'],
            'start_date'        => $bp['effective'],
            'first_due_date'    => Dates::addMonths($bp['effective'], $index + 1),
            'grace_period_days' => 5,
            'evidence_required' => (bool) ($obligation['evidence'] ?? false),
            'amount'            => $obligation['amount'] ?? null,
            'currency'          => isset($obligation['amount']) ? 'INR' : null,
        ]);
    }

    foreach ($bp['milestones'] as $milestone) {
        $milestones->create($ctx, $contractId, [
            'title'          => $milestone['title'],
            'milestone_type' => $milestone['type'],
            'due_date'       => Dates::addMonths($today, (int) $milestone['in_months']),
            'owner_uuid'     => $owner,
            'amount'         => $milestone['amount'] ?? null,
            'currency'       => isset($milestone['amount']) ? 'INR' : null,
        ]);
    }

    seed_clauses($pdo, $envName, $cmpId, $contractId, (string) $bp['clauses']);
    seed_document($pdo, $envName, $cmpId, $contractId, (string) $bp['title']);

    if ($bp['status'] === 'active') {
        $contracts->changeStatus($ctx, $contractId, 'active', 'Seeded as an executed contract.');
    }

    $risk->assess($ctx, $contractId);

    $created++;
    echo "  {$contract['contract_number']}  {$bp['title']}\n";
}

// One contract sitting in an approval, so the approvals queue is not empty.
$pending = $contracts->create($ctx, [
    'title'             => 'Consultancy Agreement — J. Rowe (pending approval)',
    'contract_type_id'  => $typeId('consultancy'),
    'counterparty_name' => 'J. Rowe Consulting',
    'effective_date'    => Dates::addMonths($today, 1),
    'expiry_date'       => Dates::addMonths($today, 13),
    'currency'          => 'INR',
    'total_value'       => '1800000.00',
    'governing_law'     => 'India',
    'owner_uuid'        => $owner,
]);
$pdo->prepare("UPDATE contracts SET metadata = '{\"seeded\":true}'::jsonb WHERE id = ?")
    ->execute([(int) $pending['id']]);

$workflowExists = $pdo->prepare(
    'SELECT id FROM approval_workflows WHERE environment = ? AND cmp_id = ? AND name = ? LIMIT 1'
);
$workflowExists->execute([$envName, $cmpId, 'Demo — value over 10 lakh']);
$workflowId = $workflowExists->fetchColumn();

if ($workflowId === false) {
    $st = $pdo->prepare(
        'INSERT INTO approval_workflows (environment, cmp_id, name, applies_to, conditions, match_mode, priority)
         VALUES (?, ?, ?, ?, ?::jsonb, ?, ?) RETURNING id'
    );
    $st->execute([
        $envName, $cmpId, 'Demo — value over 10 lakh', 'contract',
        json_encode([['field' => 'total_value', 'operator' => 'gte', 'value' => 1000000]]),
        'all', 50,
    ]);
    $workflowId = $st->fetchColumn();

    $pdo->prepare(
        'INSERT INTO approval_workflow_steps
         (workflow_id, environment, cmp_id, step_no, name, execution, approver_type, approver_value, min_approvals, escalation_days)
         VALUES (?, ?, ?, 1, ?, ?, ?, ?, 1, 3)'
    )->execute([(int) $workflowId, $envName, $cmpId, 'Legal review', 'sequential', 'role', 'legal']);
}

try {
    (new ApprovalService($pdo))->submit($ctx, 'contract', (int) $pending['id'], (int) $pending['id']);
    echo "  {$pending['contract_number']}  awaiting approval\n";
} catch (Throwable $e) {
    echo "  {$pending['contract_number']}  (no approver configured — grant someone the 'legal' role to see the queue)\n";
}

$created++;

echo "\nSeeded {$created} contracts into company {$cmpId} ({$envName}).\n";
echo "Re-run with --reset to replace them.\n";
