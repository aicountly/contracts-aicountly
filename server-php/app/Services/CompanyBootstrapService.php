<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\SeedCatalog;
use PDO;
use Throwable;

/**
 * Gives a company its starting configuration the first time it opens Contracts.
 *
 * Rows are copied into the company rather than shared from a global template,
 * so every tenant-scoped query stays a plain `cmp_id = ?` with no `OR cmp_id =
 * 0` escape hatch to get wrong — and a company can rename a contract type or
 * rewrite a clause without that reaching anyone else.
 *
 * Idempotent: every insert is `ON CONFLICT DO NOTHING` on a natural key, so a
 * concurrent second request cannot double-seed and a later release adding a new
 * default type will backfill it on the next call.
 */
final class CompanyBootstrapService
{
    /** @var array<string,bool> per-process memo — the common case is "already done". */
    private static array $seen = [];

    public function __construct(private PDO $pdo)
    {
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    /**
     * @return bool true when anything was written
     */
    public function ensure(string $environment, int $cmpId, ?string $currency = null): bool
    {
        $memoKey = $environment . '|' . $cmpId;
        if (isset(self::$seen[$memoKey])) {
            return false;
        }
        self::$seen[$memoKey] = true;

        try {
            return Database::transaction($this->pdo, function (PDO $pdo) use ($environment, $cmpId, $currency): bool {
                $wrote = $this->seedSettings($pdo, $environment, $cmpId, $currency);
                $wrote = $this->seedContractTypes($pdo, $environment, $cmpId) || $wrote;
                $wrote = $this->seedClauseCategories($pdo, $environment, $cmpId) || $wrote;
                $wrote = $this->seedClauses($pdo, $environment, $cmpId) || $wrote;
                $wrote = $this->seedTemplateVariables($pdo, $environment, $cmpId) || $wrote;
                $wrote = $this->seedRiskRules($pdo, $environment, $cmpId) || $wrote;
                $wrote = $this->seedPlaybook($pdo, $environment, $cmpId) || $wrote;

                return $wrote;
            });
        } catch (Throwable $e) {
            // A company that cannot be seeded should still be able to read what
            // it has; the next request retries.
            unset(self::$seen[$memoKey]);
            error_log('[contracts][bootstrap] ' . $e->getMessage());

            return false;
        }
    }

    private function seedSettings(PDO $pdo, string $environment, int $cmpId, ?string $currency): bool
    {
        $st = $pdo->prepare(
            'INSERT INTO contract_settings (environment, cmp_id, default_currency)
             VALUES (?, ?, ?)
             ON CONFLICT (environment, cmp_id) DO NOTHING'
        );
        $st->execute([$environment, $cmpId, $currency !== null && preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'INR']);

        return $st->rowCount() > 0;
    }

    private function seedContractTypes(PDO $pdo, string $environment, int $cmpId): bool
    {
        $st = $pdo->prepare(
            'INSERT INTO contract_types
             (environment, cmp_id, code, name, description, category, counterparty_side, is_system, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, ?)
             ON CONFLICT (environment, cmp_id, code) DO NOTHING'
        );

        $wrote = false;
        $order = 10;
        foreach (SeedCatalog::contractTypes() as $type) {
            $st->execute([
                $environment, $cmpId, $type['code'], $type['name'],
                $type['description'], $type['category'], $type['side'], $order,
            ]);
            $wrote = $st->rowCount() > 0 || $wrote;
            $order += 10;
        }

        return $wrote;
    }

    private function seedClauseCategories(PDO $pdo, string $environment, int $cmpId): bool
    {
        $st = $pdo->prepare(
            'INSERT INTO clause_categories (environment, cmp_id, code, name, risk_weight, is_system, sort_order)
             VALUES (?, ?, ?, ?, ?, TRUE, ?)
             ON CONFLICT (environment, cmp_id, code) DO NOTHING'
        );

        $wrote = false;
        $order = 10;
        foreach (SeedCatalog::clauseCategories() as $category) {
            $st->execute([$environment, $cmpId, $category['code'], $category['name'], $category['weight'], $order]);
            $wrote = $st->rowCount() > 0 || $wrote;
            $order += 10;
        }

        return $wrote;
    }

    private function seedClauses(PDO $pdo, string $environment, int $cmpId): bool
    {
        $categoryIds = $this->categoryIdMap($pdo, $environment, $cmpId);

        // The library has no natural unique key (a company may legitimately
        // want two clauses of the same name), so the guard is "have we already
        // seeded a system clause with this name" rather than ON CONFLICT.
        $exists = $pdo->prepare(
            'SELECT 1 FROM clause_library
             WHERE environment = ? AND cmp_id = ? AND name = ? AND is_system LIMIT 1'
        );
        $insert = $pdo->prepare(
            'INSERT INTO clause_library
             (environment, cmp_id, category_id, name, standard_text, fallback_text,
              risk_classification, approval_status, is_system, effective_from)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'approved\', TRUE, CURRENT_DATE)'
        );

        $wrote = false;
        foreach (SeedCatalog::clauses() as $clause) {
            $exists->execute([$environment, $cmpId, $clause['name']]);
            if ($exists->fetchColumn() !== false) {
                continue;
            }
            $insert->execute([
                $environment, $cmpId,
                $categoryIds[$clause['category']] ?? null,
                $clause['name'], $clause['text'], $clause['fallback'], $clause['risk'],
            ]);
            $wrote = true;
        }

        return $wrote;
    }

    private function seedTemplateVariables(PDO $pdo, string $environment, int $cmpId): bool
    {
        $st = $pdo->prepare(
            'INSERT INTO template_variables (environment, cmp_id, var_key, label, source, source_path, data_type, is_system)
             VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)
             ON CONFLICT (environment, cmp_id, var_key) DO NOTHING'
        );

        $wrote = false;
        foreach (SeedCatalog::templateVariables() as $variable) {
            $st->execute([
                $environment, $cmpId, $variable['key'], $variable['label'],
                $variable['source'], $variable['path'], $variable['type'],
            ]);
            $wrote = $st->rowCount() > 0 || $wrote;
        }

        return $wrote;
    }

    private function seedRiskRules(PDO $pdo, string $environment, int $cmpId): bool
    {
        $st = $pdo->prepare(
            'INSERT INTO contract_risk_rules
             (environment, cmp_id, rule_key, name, description, risk_category, severity,
              subject, operator, value_text, value_numeric, value_list, applies_to_types,
              score_weight, recommendation, is_system)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, TRUE)
             ON CONFLICT (environment, cmp_id, rule_key) DO NOTHING'
        );

        $wrote = false;
        foreach (SeedCatalog::riskRules() as $rule) {
            $st->execute([
                $environment, $cmpId, $rule['key'], $rule['name'], $rule['description'],
                $rule['category'], $rule['severity'], $rule['subject'], $rule['operator'],
                $rule['value_text'] ?? null,
                $rule['value_numeric'] ?? null,
                json_encode($rule['value_list'] ?? []),
                json_encode($rule['applies_to'] ?? []),
                $rule['weight'], $rule['recommendation'],
            ]);
            $wrote = $st->rowCount() > 0 || $wrote;
        }

        return $wrote;
    }

    private function seedPlaybook(PDO $pdo, string $environment, int $cmpId): bool
    {
        $find = $pdo->prepare(
            'SELECT id FROM contract_playbooks
             WHERE environment = ? AND cmp_id = ? AND is_default LIMIT 1'
        );
        $find->execute([$environment, $cmpId]);
        $playbookId = $find->fetchColumn();

        if ($playbookId === false) {
            $create = $pdo->prepare(
                'INSERT INTO contract_playbooks (environment, cmp_id, name, description, is_default)
                 VALUES (?, ?, ?, ?, TRUE) RETURNING id'
            );
            $create->execute([
                $environment, $cmpId, 'Company Standard Playbook',
                'The default positions every contract is measured against. Edit these to match your own standards.',
            ]);
            $playbookId = $create->fetchColumn();
        }

        $categoryIds = $this->categoryIdMap($pdo, $environment, $cmpId);

        $st = $pdo->prepare(
            'INSERT INTO playbook_rules
             (playbook_id, environment, cmp_id, rule_key, category_id, rule_type, label,
              operator, expected_value, expected_numeric, expected_list, severity, risk_category, recommendation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?)
             ON CONFLICT (playbook_id, rule_key) DO NOTHING'
        );

        $wrote = false;
        foreach (SeedCatalog::playbookRules() as $rule) {
            $st->execute([
                (int) $playbookId, $environment, $cmpId, $rule['key'],
                $categoryIds[$rule['category']] ?? null,
                $rule['type'], $rule['label'],
                $rule['operator'] ?? null,
                $rule['expected_value'] ?? null,
                $rule['expected_numeric'] ?? null,
                json_encode($rule['expected_list'] ?? []),
                $rule['severity'], $rule['risk'], $rule['recommendation'],
            ]);
            $wrote = $st->rowCount() > 0 || $wrote;
        }

        return $wrote;
    }

    /** @return array<string,int> category code → id */
    private function categoryIdMap(PDO $pdo, string $environment, int $cmpId): array
    {
        $st = $pdo->prepare('SELECT id, code FROM clause_categories WHERE environment = ? AND cmp_id = ?');
        $st->execute([$environment, $cmpId]);

        $map = [];
        foreach ($st->fetchAll() as $row) {
            $map[(string) $row['code']] = (int) $row['id'];
        }

        return $map;
    }

    /** @internal tests only */
    public static function resetMemo(): void
    {
        self::$seen = [];
    }
}
