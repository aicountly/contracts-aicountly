<?php

declare(strict_types=1);

/**
 * The schema's own guarantees: the constraints and triggers that other tests,
 * and the product's correctness, are allowed to rely on.
 */

require_once __DIR__ . '/bootstrap.php';

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

// --- every expected table exists -------------------------------------------
$expected = [
    'contracts', 'contract_types', 'contract_parties', 'contract_party_snapshots',
    'contract_documents', 'contract_document_versions', 'contract_upload_sessions',
    'contract_version_comparisons', 'contract_requests', 'contract_templates',
    'contract_template_versions', 'template_variables', 'clause_categories',
    'clause_library', 'clause_versions', 'contract_clauses', 'clause_deviations',
    'contract_playbooks', 'playbook_rules', 'approval_workflows',
    'approval_workflow_steps', 'contract_approval_instances',
    'contract_approval_assignments', 'contract_approval_actions',
    'contract_obligations', 'obligation_occurrences', 'obligation_evidence',
    'contract_milestones', 'contract_commercial_terms', 'contract_payment_schedules',
    'contract_renewals', 'contract_amendments', 'contract_terminations',
    'contract_risk_rules', 'contract_risk_assessments', 'contract_risk_findings',
    'signature_requests', 'signature_signers', 'signature_webhook_events',
    'ai_jobs', 'ai_extractions', 'ai_contract_summaries', 'ai_conversations',
    'ai_messages', 'ai_usage_log', 'contract_linked_records', 'contract_comments',
    'contract_activity_logs', 'contract_audit_logs', 'contract_notifications',
    'contract_jobs', 'contract_job_runs', 'contract_rate_limits',
    'contract_saved_views', 'contract_favourites', 'contract_recent_views',
    'contract_settings', 'contract_departments', 'contract_user_roles',
    'contract_tags', 'contract_tag_map', 'contract_custom_fields',
    'contract_number_counters',
];

$present = array_map(
    static fn (array $r): string => (string) $r['tablename'],
    $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")->fetchAll()
);

foreach ($expected as $table) {
    assert_true(in_array($table, $present, true), "table {$table} exists");
}

// --- status is a controlled vocabulary --------------------------------------
assert_throws(
    static function () use ($pdo): void {
        $pdo->prepare('INSERT INTO contracts (environment, cmp_id, contract_number, title, status) VALUES (?,?,?,?,?)')
            ->execute(['sandbox', 1, 'C-BAD-1', 'Bad status', 'whatever-i-like']);
    },
    'contracts.status rejects a free-text status',
    'ck_contracts_status'
);

// --- a contract cannot expire before it starts ------------------------------
assert_throws(
    static function () use ($pdo): void {
        $pdo->prepare('INSERT INTO contracts (environment, cmp_id, contract_number, title, effective_date, expiry_date) VALUES (?,?,?,?,?,?)')
            ->execute(['sandbox', 1, 'C-BAD-2', 'Backwards', '2026-06-01', '2026-01-01']);
    },
    'contracts rejects expiry before effective date',
    'ck_contracts_dates'
);

// --- contract numbers are unique per tenant, and only per tenant ------------
// Identity values are consumed by the failed inserts above, so the id is read
// back rather than assumed to be 1.
$insert = $pdo->prepare('INSERT INTO contracts (environment, cmp_id, contract_number, title) VALUES (?,?,?,?) RETURNING id');
$insert->execute(['sandbox', 1, 'CON-2026-000001', 'First']);
$contractId = (int) $insert->fetchColumn();

assert_throws(
    static function () use ($insert): void {
        $insert->execute(['sandbox', 1, 'CON-2026-000001', 'Duplicate']);
    },
    'contract_number is unique within a company',
    'uq_contracts_number'
);

$insert->execute(['sandbox', 2, 'CON-2026-000001', 'Same number, other company']);
t_ok('the same contract number is allowed in a different company');

// --- the audit log is append-only -------------------------------------------
$pdo->prepare('INSERT INTO contract_audit_logs (environment, cmp_id, entity_type, action, actor_uuid) VALUES (?,?,?,?,?)')
    ->execute(['sandbox', 1, 'contract', 'created', 'USER-A']);

assert_throws(
    static function () use ($pdo): void {
        $pdo->exec("UPDATE contract_audit_logs SET action = 'tampered'");
    },
    'contract_audit_logs refuses UPDATE',
    'append-only'
);

assert_throws(
    static function () use ($pdo): void {
        $pdo->exec('DELETE FROM contract_audit_logs');
    },
    'contract_audit_logs refuses DELETE',
    'append-only'
);

// --- search vector is maintained by the trigger -----------------------------
$row = $pdo->query("SELECT search_vector FROM contracts WHERE contract_number = 'CON-2026-000001' AND cmp_id = 1")->fetch();
assert_not_null($row['search_vector'] ?? null, 'search_vector is populated on insert');
assert_contains('first', strtolower((string) $row['search_vector']), 'search_vector contains the title');

// --- currency is validated ---------------------------------------------------
assert_throws(
    static function () use ($pdo): void {
        $pdo->prepare('INSERT INTO contracts (environment, cmp_id, contract_number, title, currency) VALUES (?,?,?,?,?)')
            // Three characters, so this reaches the CHECK rather than being
            // stopped by VARCHAR(3) length — the constraint under test is the
            // uppercase-ISO shape, not the column width.
            ->execute(['sandbox', 1, 'C-BAD-3', 'Bad currency', 'inr']);
    },
    'currency must be an uppercase 3-letter ISO code',
    'ck_contracts_currency'
);

// --- a document must belong to something ------------------------------------
assert_throws(
    static function () use ($pdo): void {
        $pdo->prepare('INSERT INTO contract_documents (environment, cmp_id, title) VALUES (?,?,?)')
            ->execute(['sandbox', 1, 'Orphan']);
    },
    'a document must belong to a contract, request or amendment',
    'ck_contract_documents_owner'
);

// --- a document version must point at real storage --------------------------
$pdo->prepare('INSERT INTO contract_documents (id, environment, cmp_id, contract_id, title) VALUES (900,?,?,?,?)')
    ->execute(['sandbox', 1, $contractId, 'Doc']);

assert_throws(
    static function () use ($pdo): void {
        $pdo->prepare(
            'INSERT INTO contract_document_versions
             (document_id, environment, cmp_id, version_no, filename, content_type, storage_provider)
             VALUES (900, ?, ?, 1, ?, ?, ?)'
        )->execute(['sandbox', 1, 'a.pdf', 'application/pdf', 'drive']);
    },
    'a drive-backed version must carry a drive_document_id',
    'ck_document_versions_location'
);

// --- obligations with a custom frequency need an interval -------------------
assert_throws(
    static function () use ($pdo, $contractId): void {
        $pdo->prepare(
            'INSERT INTO contract_obligations (environment, cmp_id, contract_id, title, frequency)
             VALUES (?, ?, ?, ?, ?)'
        )->execute(['sandbox', 1, $contractId, 'Custom with no interval', 'custom']);
    },
    'a custom-frequency obligation must state its interval',
    'ck_obligations_custom_interval'
);

// --- one current risk assessment per contract -------------------------------
$ins = $pdo->prepare(
    'INSERT INTO contract_risk_assessments (environment, cmp_id, contract_id, overall_score, risk_level, is_current)
     VALUES (?,?,?,?,?,TRUE)'
);
$ins->execute(['sandbox', 1, $contractId, 20, 'low']);
assert_throws(
    static function () use ($ins, $contractId): void {
        $ins->execute(['sandbox', 1, $contractId, 40, 'medium']);
    },
    'a contract may have only one current risk assessment',
    'uq_risk_assessment_current'
);

t_done('SchemaTest');
