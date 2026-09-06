<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Ai\AiProviderFactory;
use App\Core\Database;
use App\Core\Env;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\ContractService;
use App\Services\NumberingService;
use App\Services\RiskEngine;
use App\Services\RoleService;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use App\Support\ValidationFailed;
use App\Support\Validator;
use PDO;
use PDOException;
use Throwable;

/**
 * Per-company configuration: numbering, reminder ladders, taxonomy, custom
 * fields, risk rules, role grants and saved views.
 *
 * The queries live here rather than behind a service because there is no domain
 * logic to hold — each of these tables is a flat list with a unique key, and a
 * service would be a pass-through with a longer call stack (the same reasoning
 * as CommercialController). What does not move is the tenant filter: every
 * statement below carries `environment` and `cmp_id` from the TenantContext,
 * and every write leaves an audit row.
 *
 * Two shapes repeat, and both are deliberate. Every field is read and validated
 * before the statement runs, because a row written and then reported as a 422
 * is a row nobody goes back to clean up. And a PUT replaces the resource, so a
 * nullable column the caller omits is cleared; COALESCE guards only the NOT
 * NULL columns, where "omitted" can only mean "leave it alone".
 */
final class SettingsController extends BaseController
{
    /**
     * Mirrors ck_custom_fields_key_shape in 001_foundation.sql.
     *
     * Checked here as well as in the database because the key reaches a JSONB
     * path: a violation caught by the constraint surfaces as a 500 the user
     * cannot act on, and this turns it into a 422 the form can point at.
     */
    private const FIELD_KEY_SHAPE = '/^[a-z][a-z0-9_]{0,62}$/';

    /** A code appears in exports and URLs, so it stays plain. */
    private const CODE_SHAPE = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    /** Screens a saved view can belong to. Free text here would be an unfilterable column. */
    private const VIEW_SCOPES = [
        'repository', 'obligations', 'renewals', 'approvals', 'risks',
        'requests', 'amendments', 'reports',
    ];

    private const AUDITED_SETTINGS = [
        'number_prefix', 'number_pad', 'number_include_year', 'number_reset_yearly',
        'default_currency', 'default_notice_days', 'expiry_alert_days',
        'obligation_alert_days', 'approval_escalation_days', 'ai_enabled',
        'ai_auto_extract', 'ai_auto_risk', 'default_role',
    ];

    private const AUDITED_TYPE = [
        'code', 'name', 'category', 'counterparty_side', 'default_renewal_type',
        'default_notice_days', 'default_term_months', 'default_template_id',
        'approval_workflow_id', 'is_active', 'sort_order',
    ];

    private const AUDITED_DEPARTMENT = ['name', 'code', 'head_uuid', 'is_active'];

    private const AUDITED_CUSTOM_FIELD = [
        'label', 'field_type', 'contract_type_id', 'is_required',
        'is_filterable', 'help_text', 'sort_order', 'is_active',
    ];

    private const AUDITED_RISK_RULE = [
        'rule_key', 'name', 'risk_category', 'severity', 'subject', 'operator',
        'value_text', 'value_numeric', 'score_weight', 'is_active',
    ];

    // -----------------------------------------------------------------------
    // The settings row
    // -----------------------------------------------------------------------

    public function index(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $pdo = $this->db();

        Response::success([
            'settings'          => $this->settingsRow($ctx, $pdo),
            'numbering_preview' => $this->numberingPreview($ctx, $pdo),
        ]);
    }

    public function update(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $this->respond(function () use ($ctx): array {
            $pdo    = $this->db();
            $before = $this->settingsRow($ctx, $pdo);
            $v      = new Validator($this->body());

            $fields = [
                'number_prefix'            => self::prefix($v, 'number_prefix'),
                'number_pad'               => $v->optionalInt('number_pad', 3, 12),
                'number_include_year'      => self::pgBool($v->optionalBool('number_include_year')),
                'number_reset_yearly'      => self::pgBool($v->optionalBool('number_reset_yearly')),
                'default_currency'         => self::currency($v, 'default_currency'),
                'default_notice_days'      => $v->optionalInt('default_notice_days', 0, 3650),
                'expiry_alert_days'        => self::ladder($v, 'expiry_alert_days'),
                'obligation_alert_days'    => self::ladder($v, 'obligation_alert_days'),
                'approval_escalation_days' => $v->optionalInt('approval_escalation_days', 0, 365),
                'ai_enabled'               => self::pgBool($v->optionalBool('ai_enabled')),
                'ai_auto_extract'          => self::pgBool($v->optionalBool('ai_auto_extract')),
                'ai_auto_risk'             => self::pgBool($v->optionalBool('ai_auto_risk')),
                'default_role'             => $v->optionalString('default_role', 32),
            ];

            if ($fields['default_role'] !== null && ! Permissions::isKnownRole($fields['default_role'])) {
                $v->fail('default_role', 'Choose one of: ' . implode(', ', Permissions::roleSlugs()) . '.');
            }

            // settings_json is merged rather than replaced: it is the open end
            // of this table, and a screen that knows about one key would
            // otherwise delete every key it has never heard of.
            $extra         = is_array($before['settings_json'] ?? null) ? $before['settings_json'] : [];
            $extra         = array_merge($extra, $v->optionalObject('settings_json'));
            $requestPrefix = self::prefix($v, 'request_prefix');
            if ($requestPrefix !== null) {
                $extra['request_prefix'] = $requestPrefix;
            }

            $v->assert();

            $pdo->prepare(
                'UPDATE contract_settings SET
                    number_prefix = COALESCE(?, number_prefix),
                    number_pad = COALESCE(?::int, number_pad),
                    number_include_year = COALESCE(?::boolean, number_include_year),
                    number_reset_yearly = COALESCE(?::boolean, number_reset_yearly),
                    default_currency = COALESCE(?, default_currency),
                    default_notice_days = COALESCE(?::int, default_notice_days),
                    expiry_alert_days = COALESCE(?, expiry_alert_days),
                    obligation_alert_days = COALESCE(?, obligation_alert_days),
                    approval_escalation_days = COALESCE(?::int, approval_escalation_days),
                    ai_enabled = COALESCE(?::boolean, ai_enabled),
                    ai_auto_extract = COALESCE(?::boolean, ai_auto_extract),
                    ai_auto_risk = COALESCE(?::boolean, ai_auto_risk),
                    default_role = COALESCE(?, default_role),
                    settings_json = ?::jsonb,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE environment = ? AND cmp_id = ?'
            )->execute(array_merge(
                array_values($fields),
                [json_encode($extra, JSON_UNESCAPED_SLASHES), $ctx->environment, $ctx->cmpId]
            ));

            $after = $this->settingsRow($ctx, $pdo);

            (new AuditService($pdo))->logChanges(
                $ctx,
                'contract_settings',
                (int) ($after['id'] ?? 0),
                $before,
                $after,
                self::AUDITED_SETTINGS,
                null,
                'settings.updated'
            );

            // Recomputed after the write rather than predicted before it: an
            // admin changing the prefix or the padding wants to see the number
            // the next contract will actually get.
            return ['settings' => $after, 'numbering_preview' => $this->numberingPreview($ctx, $pdo)];
        });
    }

    // -----------------------------------------------------------------------
    // Contract types
    // -----------------------------------------------------------------------

    /** A form needs this list, so it is readable by anyone who can see a contract. */
    public function contractTypes(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $pdo = $this->db();

        $st = $pdo->prepare(
            'SELECT * FROM contract_types
             WHERE environment = ? AND cmp_id = ?
             ORDER BY sort_order, name'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        Response::success(array_map(self::hydrateType(...), $st->fetchAll() ?: []));
    }

    public function storeContractType(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $this->respond(function () use ($ctx): array {
            $pdo = $this->db();
            $v   = new Validator($this->body());

            $code     = self::code($v, 'code');
            $name     = $v->requiredString('name', 160);
            $template = $v->optionalId('default_template_id');
            $workflow = $v->optionalId('approval_workflow_id');

            $fields = [
                'description'          => $v->optionalText('description', 4000),
                'category'             => $v->optionalString('category', 64, 'general') ?? 'general',
                'counterparty_side'    => $v->optionalEnum('counterparty_side', ['customer', 'vendor', 'internal', 'either'], 'either') ?? 'either',
                'default_renewal_type' => $v->optionalEnum('default_renewal_type', Enums::RENEWAL_TYPES),
                'default_notice_days'  => $v->optionalInt('default_notice_days', 0, 3650),
                'default_term_months'  => $v->optionalInt('default_term_months', 0, 1200),
                'required_fields'      => self::jsonOrNull($v, 'required_fields') ?? '[]',
                'mandatory_clauses'    => self::jsonOrNull($v, 'mandatory_clauses') ?? '[]',
                'is_active'            => self::pgBool($v->optionalBool('is_active', true)),
                'sort_order'           => $v->optionalInt('sort_order', 0, 100000, 100) ?? 100,
            ];

            $this->requireOwnRow($pdo, $ctx, $v, 'contract_templates', 'default_template_id', $template);
            $this->requireOwnRow($pdo, $ctx, $v, 'approval_workflows', 'approval_workflow_id', $workflow);
            $v->assert();

            $st = $pdo->prepare(
                'INSERT INTO contract_types
                 (environment, cmp_id, code, name, description, category, counterparty_side,
                  default_renewal_type, default_notice_days, default_term_months,
                  required_fields, mandatory_clauses, default_template_id, approval_workflow_id,
                  is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?::boolean, ?)
                 RETURNING *'
            );

            try {
                $st->execute([
                    $ctx->environment, $ctx->cmpId, $code, $name,
                    $fields['description'], $fields['category'], $fields['counterparty_side'],
                    $fields['default_renewal_type'], $fields['default_notice_days'],
                    $fields['default_term_months'], $fields['required_fields'],
                    $fields['mandatory_clauses'], $template, $workflow,
                    $fields['is_active'], $fields['sort_order'],
                ]);
            } catch (PDOException $e) {
                throw self::asDuplicate($e, 'code', 'A contract type with this code already exists.');
            }

            $row = self::hydrateType($st->fetch() ?: []);
            (new AuditService($pdo))->log($ctx, 'contract_type', (int) ($row['id'] ?? 0), 'settings.contract_type_created', null, [
                'code' => ['from' => null, 'to' => $code],
                'name' => ['from' => null, 'to' => $name],
            ]);

            return $row;
        }, 201);
    }

    public function updateContractType(?string $id = null): void
    {
        $ctx    = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $typeId = $this->intId($id);

        $this->respond(function () use ($ctx, $typeId): array {
            $pdo      = $this->db();
            $existing = $this->tenantRow($pdo, 'contract_types', $ctx, $typeId, 'Contract type not found.');
            $v        = new Validator($this->body());

            $template = $v->optionalId('default_template_id');
            $workflow = $v->optionalId('approval_workflow_id');

            $fields = [
                'code'                 => self::code($v, 'code', false),
                'name'                 => $v->optionalString('name', 160),
                'description'          => $v->optionalText('description', 4000),
                'category'             => $v->optionalString('category', 64),
                'counterparty_side'    => $v->optionalEnum('counterparty_side', ['customer', 'vendor', 'internal', 'either']),
                'default_renewal_type' => $v->optionalEnum('default_renewal_type', Enums::RENEWAL_TYPES),
                'default_notice_days'  => $v->optionalInt('default_notice_days', 0, 3650),
                'default_term_months'  => $v->optionalInt('default_term_months', 0, 1200),
                'required_fields'      => self::jsonOrNull($v, 'required_fields'),
                'mandatory_clauses'    => self::jsonOrNull($v, 'mandatory_clauses'),
                'default_template_id'  => $template,
                'approval_workflow_id' => $workflow,
                'is_active'            => self::pgBool($v->optionalBool('is_active')),
                'sort_order'           => $v->optionalInt('sort_order', 0, 100000),
            ];

            $this->requireOwnRow($pdo, $ctx, $v, 'contract_templates', 'default_template_id', $template);
            $this->requireOwnRow($pdo, $ctx, $v, 'approval_workflows', 'approval_workflow_id', $workflow);
            $v->assert();

            $st = $pdo->prepare(
                'UPDATE contract_types SET
                    code = COALESCE(?, code),
                    name = COALESCE(?, name),
                    description = ?,
                    category = COALESCE(?, category),
                    counterparty_side = COALESCE(?, counterparty_side),
                    default_renewal_type = ?,
                    default_notice_days = ?,
                    default_term_months = ?,
                    required_fields = COALESCE(?::jsonb, required_fields),
                    mandatory_clauses = COALESCE(?::jsonb, mandatory_clauses),
                    default_template_id = ?,
                    approval_workflow_id = ?,
                    is_active = COALESCE(?::boolean, is_active),
                    sort_order = COALESCE(?::int, sort_order),
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?
                 RETURNING *'
            );

            try {
                $st->execute(array_merge(array_values($fields), [$typeId, $ctx->environment, $ctx->cmpId]));
            } catch (PDOException $e) {
                throw self::asDuplicate($e, 'code', 'A contract type with this code already exists.');
            }

            $row = self::hydrateType($st->fetch() ?: []);
            (new AuditService($pdo))->logChanges(
                $ctx,
                'contract_type',
                $typeId,
                self::hydrateType($existing),
                $row,
                self::AUDITED_TYPE,
                null,
                'settings.contract_type_updated'
            );

            return $row;
        });
    }

    public function destroyContractType(?string $id = null): void
    {
        $ctx    = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $typeId = $this->intId($id);

        $this->run(function () use ($ctx, $typeId): bool {
            $pdo = $this->db();
            $row = $this->tenantRow($pdo, 'contract_types', $ctx, $typeId, 'Contract type not found.');

            $this->refuseSystemDelete($row, 'contract type');

            // contracts.contract_type_id is ON DELETE SET NULL and a playbook
            // scoped to this type is ON DELETE CASCADE, so a delete here would
            // quietly untype live contracts and take their playbook with them.
            $inUse = $this->countReferences($pdo, 'contracts', 'contract_type_id', $ctx, $typeId);
            if ($inUse > 0) {
                throw DomainException::conflict(
                    sprintf('%d contract(s) still use this type. Deactivate it instead of deleting it.', $inUse)
                );
            }

            $pdo->prepare('DELETE FROM contract_types WHERE id = ? AND environment = ? AND cmp_id = ?')
                ->execute([$typeId, $ctx->environment, $ctx->cmpId]);

            (new AuditService($pdo))->log($ctx, 'contract_type', $typeId, 'settings.contract_type_deleted', null, [
                'code' => ['from' => $row['code'], 'to' => null],
            ]);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    // -----------------------------------------------------------------------
    // Departments
    // -----------------------------------------------------------------------

    public function departments(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $pdo = $this->db();

        $st = $pdo->prepare(
            'SELECT * FROM contract_departments
             WHERE environment = ? AND cmp_id = ? ORDER BY name'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        Response::success(array_map(self::hydrateDepartment(...), $st->fetchAll() ?: []));
    }

    public function storeDepartment(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $this->respond(function () use ($ctx): array {
            $pdo    = $this->db();
            $v      = new Validator($this->body());
            $name   = $v->requiredString('name', 120);
            $code   = self::code($v, 'code');
            $head   = $v->optionalString('head_uuid', 64);
            $active = self::pgBool($v->optionalBool('is_active', true));
            $v->assert();

            $st = $pdo->prepare(
                'INSERT INTO contract_departments (environment, cmp_id, name, code, head_uuid, is_active)
                 VALUES (?, ?, ?, ?, ?, ?::boolean) RETURNING *'
            );

            try {
                $st->execute([$ctx->environment, $ctx->cmpId, $name, $code, $head, $active]);
            } catch (PDOException $e) {
                throw self::asDuplicate($e, 'code', 'A department with this code already exists.');
            }

            $row = self::hydrateDepartment($st->fetch() ?: []);
            (new AuditService($pdo))->log($ctx, 'department', (int) ($row['id'] ?? 0), 'settings.department_created', null, [
                'code' => ['from' => null, 'to' => $code],
                'name' => ['from' => null, 'to' => $name],
            ]);

            return $row;
        }, 201);
    }

    public function updateDepartment(?string $id = null): void
    {
        $ctx          = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $departmentId = $this->intId($id);

        $this->respond(function () use ($ctx, $departmentId): array {
            $pdo      = $this->db();
            $existing = $this->tenantRow($pdo, 'contract_departments', $ctx, $departmentId, 'Department not found.');
            $v        = new Validator($this->body());

            $fields = [
                'name'      => $v->optionalString('name', 120),
                'code'      => self::code($v, 'code', false),
                'head_uuid' => $v->optionalString('head_uuid', 64),
                'is_active' => self::pgBool($v->optionalBool('is_active')),
            ];
            $v->assert();

            $st = $pdo->prepare(
                'UPDATE contract_departments SET
                    name = COALESCE(?, name),
                    code = COALESCE(?, code),
                    head_uuid = ?,
                    is_active = COALESCE(?::boolean, is_active),
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?
                 RETURNING *'
            );

            try {
                $st->execute(array_merge(array_values($fields), [$departmentId, $ctx->environment, $ctx->cmpId]));
            } catch (PDOException $e) {
                throw self::asDuplicate($e, 'code', 'A department with this code already exists.');
            }

            $row = self::hydrateDepartment($st->fetch() ?: []);
            (new AuditService($pdo))->logChanges(
                $ctx,
                'department',
                $departmentId,
                self::hydrateDepartment($existing),
                $row,
                self::AUDITED_DEPARTMENT,
                null,
                'settings.department_updated'
            );

            return $row;
        });
    }

    public function destroyDepartment(?string $id = null): void
    {
        $ctx          = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $departmentId = $this->intId($id);

        $this->run(function () use ($ctx, $departmentId): bool {
            $pdo = $this->db();
            $row = $this->tenantRow($pdo, 'contract_departments', $ctx, $departmentId, 'Department not found.');

            $inUse = $this->countReferences($pdo, 'contracts', 'department_id', $ctx, $departmentId);
            if ($inUse > 0) {
                throw DomainException::conflict(
                    sprintf('%d contract(s) are still filed under this department. Deactivate it instead.', $inUse)
                );
            }

            $pdo->prepare('DELETE FROM contract_departments WHERE id = ? AND environment = ? AND cmp_id = ?')
                ->execute([$departmentId, $ctx->environment, $ctx->cmpId]);

            (new AuditService($pdo))->log($ctx, 'department', $departmentId, 'settings.department_deleted', null, [
                'code' => ['from' => $row['code'], 'to' => null],
            ]);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    // -----------------------------------------------------------------------
    // Custom fields
    // -----------------------------------------------------------------------

    public function customFields(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $pdo = $this->db();

        $st = $pdo->prepare(
            'SELECT * FROM contract_custom_fields
             WHERE environment = ? AND cmp_id = ? ORDER BY sort_order, label'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        Response::success(array_map(self::hydrateCustomField(...), $st->fetchAll() ?: []));
    }

    public function storeCustomField(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $this->respond(function () use ($ctx): array {
            $pdo = $this->db();
            $v   = new Validator($this->body());

            $key = $v->requiredString('field_key', 64);
            if ($key !== '' && preg_match(self::FIELD_KEY_SHAPE, $key) !== 1) {
                $v->fail('field_key', 'Use a lowercase key starting with a letter, such as cost_centre.');
            }

            $label  = $v->requiredString('label', 160);
            $type   = $v->requiredEnum('field_type', Enums::CUSTOM_FIELD_TYPES);
            $typeId = $v->optionalId('contract_type_id');

            $fields = [
                'options'       => self::jsonOrNull($v, 'options') ?? '[]',
                'is_required'   => self::pgBool($v->optionalBool('is_required', false)),
                'is_filterable' => self::pgBool($v->optionalBool('is_filterable', false)),
                'help_text'     => $v->optionalString('help_text', 255),
                'sort_order'    => $v->optionalInt('sort_order', 0, 100000, 100) ?? 100,
                'is_active'     => self::pgBool($v->optionalBool('is_active', true)),
            ];

            $this->requireOwnRow($pdo, $ctx, $v, 'contract_types', 'contract_type_id', $typeId);
            $v->assert();

            $st = $pdo->prepare(
                'INSERT INTO contract_custom_fields
                 (environment, cmp_id, field_key, label, field_type, contract_type_id,
                  options, is_required, is_filterable, help_text, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?::boolean, ?::boolean, ?, ?, ?::boolean)
                 RETURNING *'
            );

            try {
                $st->execute(array_merge(
                    [$ctx->environment, $ctx->cmpId, $key, $label, $type, $typeId],
                    array_values($fields)
                ));
            } catch (PDOException $e) {
                throw self::asDuplicate($e, 'field_key', 'A custom field with this key already exists.');
            }

            $row = self::hydrateCustomField($st->fetch() ?: []);
            (new AuditService($pdo))->log($ctx, 'custom_field', (int) ($row['id'] ?? 0), 'settings.custom_field_created', null, [
                'field_key' => ['from' => null, 'to' => $key],
                'label'     => ['from' => null, 'to' => $label],
            ]);

            return $row;
        }, 201);
    }

    /**
     * Everything about a custom field except its key.
     *
     * Contracts store their values in a JSONB object keyed by `field_key`, so
     * renaming it here would orphan every value already recorded under the old
     * name. A rename is a new field.
     */
    public function updateCustomField(?string $id = null): void
    {
        $ctx     = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $fieldId = $this->intId($id);

        $this->respond(function () use ($ctx, $fieldId): array {
            $pdo      = $this->db();
            $existing = $this->tenantRow($pdo, 'contract_custom_fields', $ctx, $fieldId, 'Custom field not found.');
            $v        = new Validator($this->body());

            $typeId = $v->optionalId('contract_type_id');
            $fields = [
                'label'            => $v->optionalString('label', 160),
                'field_type'       => $v->optionalEnum('field_type', Enums::CUSTOM_FIELD_TYPES),
                'contract_type_id' => $typeId,
                'options'          => self::jsonOrNull($v, 'options'),
                'is_required'      => self::pgBool($v->optionalBool('is_required')),
                'is_filterable'    => self::pgBool($v->optionalBool('is_filterable')),
                'help_text'        => $v->optionalString('help_text', 255),
                'sort_order'       => $v->optionalInt('sort_order', 0, 100000),
                'is_active'        => self::pgBool($v->optionalBool('is_active')),
            ];

            $this->requireOwnRow($pdo, $ctx, $v, 'contract_types', 'contract_type_id', $typeId);
            $v->assert();

            $st = $pdo->prepare(
                'UPDATE contract_custom_fields SET
                    label = COALESCE(?, label),
                    field_type = COALESCE(?, field_type),
                    contract_type_id = ?,
                    options = COALESCE(?::jsonb, options),
                    is_required = COALESCE(?::boolean, is_required),
                    is_filterable = COALESCE(?::boolean, is_filterable),
                    help_text = ?,
                    sort_order = COALESCE(?::int, sort_order),
                    is_active = COALESCE(?::boolean, is_active),
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?
                 RETURNING *'
            );
            $st->execute(array_merge(array_values($fields), [$fieldId, $ctx->environment, $ctx->cmpId]));

            $row = self::hydrateCustomField($st->fetch() ?: []);
            (new AuditService($pdo))->logChanges(
                $ctx,
                'custom_field',
                $fieldId,
                self::hydrateCustomField($existing),
                $row,
                self::AUDITED_CUSTOM_FIELD,
                null,
                'settings.custom_field_updated'
            );

            return $row;
        });
    }

    public function destroyCustomField(?string $id = null): void
    {
        $ctx     = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $fieldId = $this->intId($id);

        $this->run(function () use ($ctx, $fieldId): bool {
            $pdo = $this->db();
            $row = $this->tenantRow($pdo, 'contract_custom_fields', $ctx, $fieldId, 'Custom field not found.');

            // Only the definition goes. Values already recorded against
            // contracts stay in their JSONB column, because rewriting historical
            // contracts to tidy up a settings screen would destroy evidence.
            $pdo->prepare('DELETE FROM contract_custom_fields WHERE id = ? AND environment = ? AND cmp_id = ?')
                ->execute([$fieldId, $ctx->environment, $ctx->cmpId]);

            (new AuditService($pdo))->log($ctx, 'custom_field', $fieldId, 'settings.custom_field_deleted', null, [
                'field_key' => ['from' => $row['field_key'], 'to' => null],
            ]);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    // -----------------------------------------------------------------------
    // Tags
    // -----------------------------------------------------------------------

    public function tags(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $pdo = $this->db();

        $st = $pdo->prepare(
            'SELECT t.id, t.name, t.colour, t.created_at,
                    (SELECT COUNT(*) FROM contract_tag_map m WHERE m.tag_id = t.id) AS usage_count
             FROM contract_tags t
             WHERE t.environment = ? AND t.cmp_id = ? ORDER BY t.name'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        Response::success(array_map(
            static function (array $row): array {
                $row['id']          = (int) $row['id'];
                $row['usage_count'] = (int) $row['usage_count'];

                return $row;
            },
            $st->fetchAll() ?: []
        ));
    }

    public function storeTag(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $this->respond(function () use ($ctx): array {
            $pdo  = $this->db();
            $v    = new Validator($this->body());
            $name = $v->requiredString('name', 64);

            // The colour is rendered as a CSS class name, so it is a token from
            // a palette rather than free text a caller can style with.
            $colour = $v->optionalString('colour', 16, 'slate') ?? 'slate';
            if (preg_match('/^[a-z][a-z0-9_-]{0,15}$/', $colour) !== 1) {
                $v->fail('colour', 'Choose a palette colour, such as slate or amber.');
            }
            $v->assert();

            $st = $pdo->prepare(
                'INSERT INTO contract_tags (environment, cmp_id, name, colour) VALUES (?, ?, ?, ?) RETURNING *'
            );

            try {
                $st->execute([$ctx->environment, $ctx->cmpId, $name, $colour]);
            } catch (PDOException $e) {
                throw self::asDuplicate($e, 'name', 'A tag with this name already exists.');
            }

            $row       = $st->fetch() ?: [];
            $row['id'] = (int) ($row['id'] ?? 0);

            (new AuditService($pdo))->log($ctx, 'tag', $row['id'], 'settings.tag_created', null, [
                'name' => ['from' => null, 'to' => $name],
            ]);

            return $row;
        }, 201);
    }

    public function destroyTag(?string $id = null): void
    {
        $ctx   = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $tagId = $this->intId($id);
        $pdo   = $this->db();

        // contract_tag_map cascades, so the assignments go with the tag. That is
        // what deleting a label means; no contract is otherwise changed by it.
        $st = $pdo->prepare(
            'DELETE FROM contract_tags WHERE id = ? AND environment = ? AND cmp_id = ? RETURNING name'
        );
        $st->execute([$tagId, $ctx->environment, $ctx->cmpId]);
        $name = $st->fetchColumn();

        if ($name === false) {
            Response::notFound('Tag not found.');
        }

        (new AuditService($pdo))->log($ctx, 'tag', $tagId, 'settings.tag_deleted', null, [
            'name' => ['from' => $name, 'to' => null],
        ]);

        Response::success(['deleted' => true]);
    }

    // -----------------------------------------------------------------------
    // Roles
    // -----------------------------------------------------------------------

    public function roles(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $catalogue = [];
        foreach (Permissions::roles() as $slug => $role) {
            $catalogue[] = [
                'slug'        => $slug,
                'label'       => $role['label'],
                'description' => $role['description'],
                'permissions' => $role['permissions'],
            ];
        }

        Response::success([
            'roles'        => $catalogue,
            'grants'       => RoleService::listGrants($ctx->environment, $ctx->cmpId),
            'permissions'  => Permissions::all(),
            'default_role' => RoleService::defaultRole($ctx->environment, $ctx->cmpId),
        ]);
    }

    public function grantRole(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $this->respond(function () use ($ctx): array {
            [$uuid, $slug] = $this->roleTarget();

            if (! RoleService::grant($ctx->environment, $ctx->cmpId, $uuid, $slug, $ctx->uuid)) {
                throw DomainException::conflict('That role could not be granted.');
            }

            (new AuditService($this->db()))->log($ctx, 'user_role', null, 'settings.role_granted', null, [
                'user_uuid' => ['from' => null, 'to' => $uuid],
                'role_slug' => ['from' => null, 'to' => $slug],
            ]);

            return [
                'user_uuid' => $uuid,
                'roles'     => RoleService::rolesFor($ctx->environment, $ctx->cmpId, $uuid),
            ];
        });
    }

    public function revokeRole(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $this->respond(function () use ($ctx): array {
            [$uuid, $slug] = $this->roleTarget();

            // Removing the last administrator leaves the settings screen
            // reachable only by the company owner in Manage, which is not
            // visible from here and is a bad thing to discover later.
            if ($slug === 'contract_admin') {
                $admins = RoleService::usersWithRole($ctx->environment, $ctx->cmpId, 'contract_admin');
                if (count($admins) <= 1 && in_array($uuid, $admins, true)) {
                    throw DomainException::conflict(
                        'This is the last contract administrator. Grant the role to someone else first.'
                    );
                }
            }

            RoleService::revoke($ctx->environment, $ctx->cmpId, $uuid, $slug);

            (new AuditService($this->db()))->log($ctx, 'user_role', null, 'settings.role_revoked', null, [
                'user_uuid' => ['from' => $uuid, 'to' => $uuid],
                'role_slug' => ['from' => $slug, 'to' => null],
            ]);

            return [
                'user_uuid' => $uuid,
                'roles'     => RoleService::rolesFor($ctx->environment, $ctx->cmpId, $uuid),
            ];
        });
    }

    // -----------------------------------------------------------------------
    // Risk rules
    // -----------------------------------------------------------------------

    public function riskRules(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $pdo = $this->db();

        $st = $pdo->prepare(
            'SELECT * FROM contract_risk_rules
             WHERE environment = ? AND cmp_id = ?
             ORDER BY risk_category, rule_key'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        Response::success(array_map(self::hydrateRiskRule(...), $st->fetchAll() ?: []));
    }

    public function storeRiskRule(): void
    {
        $ctx = $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $this->respond(function () use ($ctx): array {
            $pdo  = $this->db();
            $v    = new Validator($this->body());
            $key  = self::ruleKey($v);
            $name = $v->requiredString('name', 200);

            $fields = [
                'description'      => $v->optionalText('description', 4000),
                'risk_category'    => $v->requiredEnum('risk_category', Enums::RISK_CATEGORIES),
                'severity'         => $v->optionalEnum('severity', Enums::RISK_SEVERITIES, 'medium') ?? 'medium',
                'subject'          => $v->requiredEnum('subject', RiskEngine::SUBJECTS),
                'operator'         => $v->requiredEnum('operator', RiskEngine::OPERATORS),
                'value_text'       => $v->optionalText('value_text', 4000),
                'value_numeric'    => $v->optionalDecimal('value_numeric'),
                'value_list'       => self::jsonOrNull($v, 'value_list') ?? '[]',
                'applies_to_types' => self::jsonOrNull($v, 'applies_to_types') ?? '[]',
                'score_weight'     => $v->optionalInt('score_weight', 0, 100, 10) ?? 10,
                'recommendation'   => $v->optionalText('recommendation', 4000),
                'is_active'        => self::pgBool($v->optionalBool('is_active', true)),
            ];
            $v->assert();

            $st = $pdo->prepare(
                'INSERT INTO contract_risk_rules
                 (environment, cmp_id, rule_key, name, description, risk_category, severity,
                  subject, operator, value_text, value_numeric, value_list, applies_to_types,
                  score_weight, recommendation, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?::boolean)
                 RETURNING *'
            );

            try {
                $st->execute(array_merge(
                    [$ctx->environment, $ctx->cmpId, $key, $name],
                    array_values($fields)
                ));
            } catch (PDOException $e) {
                throw self::asDuplicate($e, 'rule_key', 'A risk rule with this key already exists.');
            }

            $row = self::hydrateRiskRule($st->fetch() ?: []);
            (new AuditService($pdo))->log($ctx, 'risk_rule', (int) ($row['id'] ?? 0), 'settings.risk_rule_created', null, [
                'rule_key' => ['from' => null, 'to' => $key],
                'name'     => ['from' => null, 'to' => $name],
            ]);

            return $row;
        }, 201);
    }

    public function updateRiskRule(?string $id = null): void
    {
        $ctx    = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $ruleId = $this->intId($id);

        $this->respond(function () use ($ctx, $ruleId): array {
            $pdo      = $this->db();
            $existing = $this->tenantRow($pdo, 'contract_risk_rules', $ctx, $ruleId, 'Risk rule not found.');
            $v        = new Validator($this->body());

            $fields = [
                'rule_key'         => self::ruleKey($v, false),
                'name'             => $v->optionalString('name', 200),
                'description'      => $v->optionalText('description', 4000),
                'risk_category'    => $v->optionalEnum('risk_category', Enums::RISK_CATEGORIES),
                'severity'         => $v->optionalEnum('severity', Enums::RISK_SEVERITIES),
                'subject'          => $v->optionalEnum('subject', RiskEngine::SUBJECTS),
                'operator'         => $v->optionalEnum('operator', RiskEngine::OPERATORS),
                'value_text'       => $v->optionalText('value_text', 4000),
                'value_numeric'    => $v->optionalDecimal('value_numeric'),
                'value_list'       => self::jsonOrNull($v, 'value_list'),
                'applies_to_types' => self::jsonOrNull($v, 'applies_to_types'),
                'score_weight'     => $v->optionalInt('score_weight', 0, 100),
                'recommendation'   => $v->optionalText('recommendation', 4000),
                'is_active'        => self::pgBool($v->optionalBool('is_active')),
            ];
            $v->assert();

            $st = $pdo->prepare(
                'UPDATE contract_risk_rules SET
                    rule_key = COALESCE(?, rule_key),
                    name = COALESCE(?, name),
                    description = ?,
                    risk_category = COALESCE(?, risk_category),
                    severity = COALESCE(?, severity),
                    subject = COALESCE(?, subject),
                    operator = COALESCE(?, operator),
                    value_text = ?,
                    value_numeric = ?,
                    value_list = COALESCE(?::jsonb, value_list),
                    applies_to_types = COALESCE(?::jsonb, applies_to_types),
                    score_weight = COALESCE(?::int, score_weight),
                    recommendation = ?,
                    is_active = COALESCE(?::boolean, is_active),
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?
                 RETURNING *'
            );

            try {
                $st->execute(array_merge(array_values($fields), [$ruleId, $ctx->environment, $ctx->cmpId]));
            } catch (PDOException $e) {
                throw self::asDuplicate($e, 'rule_key', 'A risk rule with this key already exists.');
            }

            $row = self::hydrateRiskRule($st->fetch() ?: []);
            (new AuditService($pdo))->logChanges(
                $ctx,
                'risk_rule',
                $ruleId,
                self::hydrateRiskRule($existing),
                $row,
                self::AUDITED_RISK_RULE,
                null,
                'settings.risk_rule_updated'
            );

            return $row;
        });
    }

    public function destroyRiskRule(?string $id = null): void
    {
        $ctx    = $this->requirePermission(Permissions::SETTINGS_MANAGE);
        $ruleId = $this->intId($id);

        $this->run(function () use ($ctx, $ruleId): bool {
            $pdo = $this->db();
            $row = $this->tenantRow($pdo, 'contract_risk_rules', $ctx, $ruleId, 'Risk rule not found.');

            $this->refuseSystemDelete($row, 'risk rule');

            // Findings keep their history: contract_risk_findings.rule_id is
            // ON DELETE SET NULL and each finding carries its own rule_key.
            $pdo->prepare('DELETE FROM contract_risk_rules WHERE id = ? AND environment = ? AND cmp_id = ?')
                ->execute([$ruleId, $ctx->environment, $ctx->cmpId]);

            (new AuditService($pdo))->log($ctx, 'risk_rule', $ruleId, 'settings.risk_rule_deleted', null, [
                'rule_key' => ['from' => $row['rule_key'], 'to' => null],
            ]);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    // -----------------------------------------------------------------------
    // Integrations
    // -----------------------------------------------------------------------

    /**
     * What this deployment is wired to, and whether AI is usable.
     *
     * Readable by anyone who can see a contract, which is exactly why every
     * value here is a boolean or a human sentence: no key, no key prefix, no
     * host, nothing that would turn a status panel into a way to read the
     * server's configuration.
     */
    public function integrations(): void
    {
        $this->requirePermission(Permissions::CONTRACT_VIEW);

        $drive     = Env::get('DRIVE_API_BASE') !== '';
        $local     = Env::bool('CONTRACTS_ALLOW_LOCAL_STORAGE');
        $email     = Env::bool('CONTRACTS_EMAIL_ENABLED');
        $signature = Env::get('SIGNATURE_PROVIDER') !== '';

        Response::success([
            'manage' => [
                'configured' => true,
                'detail'     => 'Company, branch and financial-year context.',
            ],
            'contacts' => [
                'configured' => true,
                'detail'     => 'Counterparty lookup.',
            ],
            'drive' => [
                'configured' => $drive,
                'provider'   => $drive ? 'drive' : ($local ? 'local' : 'none'),
                'detail'     => $drive
                    ? 'Documents are stored in AICOUNTLY Drive.'
                    : ($local
                        ? 'Drive is not configured; documents are stored on this server as a temporary fallback.'
                        : 'Drive is not configured and the local fallback is disabled, so uploads are unavailable.'),
            ],
            'console' => [
                'configured' => Env::get('CONSOLE_API_URL') !== '' && Env::get('CONSOLE_SERVICE_KEY') !== '',
                'detail'     => 'AI provider credentials are issued by Console and are never stored here.',
            ],
            'signature' => [
                'configured' => $signature,
                'detail'     => $signature
                    ? 'An external signature provider is configured.'
                    : 'No signature provider configured. Contracts can still record an externally signed copy.',
            ],
            'email' => [
                'configured' => $email,
                'detail'     => $email
                    ? 'Email reminders are enabled.'
                    : 'Email is disabled; reminders appear in-app only.',
            ],
            'ai' => $this->aiStatus(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Saved views
    // -----------------------------------------------------------------------

    /**
     * A saved view is a personal filter set, not a company setting, which is
     * why this is granted with CONTRACT_VIEW and scoped to the caller: they see
     * their own views plus anything a colleague chose to share.
     */
    public function savedViews(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $pdo = $this->db();

        $st = $pdo->prepare(
            'SELECT * FROM contract_saved_views
             WHERE environment = ? AND cmp_id = ? AND (owner_uuid = ? OR is_shared)
             ORDER BY scope, name'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $ctx->uuid]);

        Response::success(array_map(self::hydrateView(...), $st->fetchAll() ?: []));
    }

    public function storeSavedView(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(function () use ($ctx): array {
            $pdo     = $this->db();
            $v       = new Validator($this->body());
            $name    = $v->requiredString('name', 120);
            $scope   = $v->optionalEnum('scope', self::VIEW_SCOPES, 'repository') ?? 'repository';
            $shared  = $v->optionalBool('is_shared', false) ?? false;
            $default = $v->optionalBool('is_default', false) ?? false;
            $filters = $v->optionalObject('filters');
            $columns = $v->optionalArray('columns', 60);
            $v->assert();

            return Database::transaction($pdo, function (PDO $pdo) use ($ctx, $name, $scope, $shared, $default, $filters, $columns): array {
                // Saving over a name the owner already used updates that view
                // rather than failing: "save" on the screen someone is already
                // looking at means "keep what I have now".
                $st = $pdo->prepare(
                    'INSERT INTO contract_saved_views
                     (environment, cmp_id, owner_uuid, name, scope, filters, columns, is_shared, is_default)
                     VALUES (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::boolean, ?::boolean)
                     ON CONFLICT (environment, cmp_id, owner_uuid, scope, name) DO UPDATE
                     SET filters = EXCLUDED.filters, columns = EXCLUDED.columns,
                         is_shared = EXCLUDED.is_shared, is_default = EXCLUDED.is_default,
                         updated_at = CURRENT_TIMESTAMP
                     RETURNING *'
                );
                $st->execute([
                    $ctx->environment, $ctx->cmpId, $ctx->uuid, $name, $scope,
                    json_encode($filters, JSON_UNESCAPED_SLASHES),
                    json_encode(array_values($columns), JSON_UNESCAPED_SLASHES),
                    self::pgBool($shared), self::pgBool($default),
                ]);

                $row = self::hydrateView($st->fetch() ?: []);

                if ($default) {
                    // One landing view per screen. Two rows flagged default
                    // would make which one opens depend on row order.
                    $pdo->prepare(
                        'UPDATE contract_saved_views SET is_default = FALSE
                         WHERE environment = ? AND cmp_id = ? AND owner_uuid = ? AND scope = ? AND id <> ?'
                    )->execute([$ctx->environment, $ctx->cmpId, $ctx->uuid, $scope, (int) ($row['id'] ?? 0)]);
                }

                (new AuditService($pdo))->log($ctx, 'saved_view', (int) ($row['id'] ?? 0), 'settings.saved_view_saved', null, [
                    'name'  => ['from' => null, 'to' => $name],
                    'scope' => ['from' => null, 'to' => $scope],
                ]);

                return $row;
            });
        }, 201);
    }

    public function destroySavedView(?string $id = null): void
    {
        $ctx    = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $viewId = $this->intId($id);
        $pdo    = $this->db();

        // Owner-scoped: a shared view still belongs to whoever saved it, and
        // removing someone else's screen out from under them is not a courtesy
        // this endpoint offers.
        $st = $pdo->prepare(
            'DELETE FROM contract_saved_views
             WHERE id = ? AND environment = ? AND cmp_id = ? AND owner_uuid = ?
             RETURNING name'
        );
        $st->execute([$viewId, $ctx->environment, $ctx->cmpId, $ctx->uuid]);
        $name = $st->fetchColumn();

        if ($name === false) {
            Response::notFound('Saved view not found.');
        }

        (new AuditService($pdo))->log($ctx, 'saved_view', $viewId, 'settings.saved_view_deleted', null, [
            'name' => ['from' => $name, 'to' => null],
        ]);

        Response::success(['deleted' => true]);
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * The settings row, materialised if this company has never had one.
     *
     * The column defaults in the migration are the only statement of what
     * "unconfigured" means; a second copy of them in PHP would drift from the
     * first the day someone changes one of the two.
     *
     * @return array<string,mixed>
     */
    private function settingsRow(TenantContext $ctx, PDO $pdo): array
    {
        $pdo->prepare(
            'INSERT INTO contract_settings (environment, cmp_id) VALUES (?, ?)
             ON CONFLICT (environment, cmp_id) DO NOTHING'
        )->execute([$ctx->environment, $ctx->cmpId]);

        $st = $pdo->prepare('SELECT * FROM contract_settings WHERE environment = ? AND cmp_id = ? LIMIT 1');
        $st->execute([$ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? self::hydrateSettings($row) : [];
    }

    /** @return array<string,string> */
    private function numberingPreview(TenantContext $ctx, PDO $pdo): array
    {
        $numbering = new NumberingService($pdo);

        return [
            'contract' => $numbering->preview($ctx->environment, $ctx->cmpId, 'contract'),
            'request'  => $numbering->preview($ctx->environment, $ctx->cmpId, 'request'),
        ];
    }

    /** @return array{0: string, 1: string} the user and the role a grant call names */
    private function roleTarget(): array
    {
        $v    = new Validator($this->body());
        $uuid = $v->requiredString('user_uuid', 64);
        $slug = $v->requiredString('role_slug', 32);

        if ($slug !== '' && ! Permissions::isKnownRole($slug)) {
            $v->fail('role_slug', 'Choose one of: ' . implode(', ', Permissions::roleSlugs()) . '.');
        }

        $v->assert();

        return [$uuid, $slug];
    }

    /**
     * One configuration row, confirmed to belong to this company.
     *
     * The table name is a literal written in this file, never caller input; the
     * only caller value in the statement is the bound id.
     *
     * @return array<string,mixed>
     */
    private function tenantRow(PDO $pdo, string $table, TenantContext $ctx, int $id, string $missing): array
    {
        $st = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1");
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound($missing);
        }

        return $row;
    }

    private function countReferences(PDO $pdo, string $table, string $column, TenantContext $ctx, int $id): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ? AND environment = ? AND cmp_id = ?");
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);

        return (int) $st->fetchColumn();
    }

    /**
     * Refuse a foreign key that points outside this company.
     *
     * The database constraint only asks whether *some* row exists, so without
     * this a caller could bind their contract type to another company's
     * template and read its name back through this screen.
     */
    private function requireOwnRow(PDO $pdo, TenantContext $ctx, Validator $v, string $table, string $field, ?int $id): void
    {
        if ($id === null) {
            return;
        }

        $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1");
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            $v->fail($field, 'That record does not exist.');
        }
    }

    /**
     * A seeded row is restored by CompanyBootstrapService the next time anyone
     * opens Contracts, so deleting one produces a row that comes back. Saying
     * no is kinder than that.
     *
     * @param array<string,mixed> $row
     */
    private function refuseSystemDelete(array $row, string $label): void
    {
        if (ContractService::toBool($row['is_system'] ?? false)) {
            throw DomainException::conflict(
                "A built-in {$label} cannot be deleted — it is restored automatically. Deactivate it instead."
            );
        }
    }

    /**
     * A reminder ladder: `90,60,30,15,7`.
     *
     * Normalised to distinct days in descending order because the sweep walks
     * them in stored order, and a ladder with 7 before 90 sends the final
     * warning first. The column holds 64 characters, which is why the number of
     * steps is capped rather than left to the caller.
     */
    private static function ladder(Validator $v, string $field): ?string
    {
        $raw = $v->optionalString($field, 64);
        if ($raw === null) {
            return null;
        }

        $days = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if (preg_match('/^\d{1,4}$/', $piece) !== 1 || (int) $piece < 1) {
                $v->fail($field, 'Enter reminder days as comma-separated positive numbers, such as 90,60,30.');

                return null;
            }
            $days[(int) $piece] = true;
        }

        if ($days === []) {
            $v->fail($field, 'Enter at least one reminder day.');

            return null;
        }
        if (count($days) > 10) {
            $v->fail($field, 'Use at most 10 reminder days.');

            return null;
        }

        $days = array_keys($days);
        rsort($days);

        return implode(',', $days);
    }

    /** A numbering prefix ends up in a contract number and in export filenames. */
    private static function prefix(Validator $v, string $field): ?string
    {
        $raw = $v->optionalString($field, 16);
        if ($raw === null) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,15}$/', $raw) !== 1) {
            $v->fail($field, 'Use letters, digits, hyphens or underscores only, such as CON.');

            return null;
        }

        return strtoupper($raw);
    }

    private static function currency(Validator $v, string $field): ?string
    {
        $raw = $v->optionalString($field, 3);
        if ($raw === null) {
            return null;
        }

        $upper = strtoupper($raw);
        if (preg_match('/^[A-Z]{3}$/', $upper) !== 1) {
            $v->fail($field, 'Enter a 3-letter currency code, such as INR.');

            return null;
        }

        return $upper;
    }

    /** A stable code for a taxonomy row. Required on create, optional on edit. */
    private static function code(Validator $v, string $field, bool $required = true): ?string
    {
        $raw = $required ? $v->requiredString($field, 64) : $v->optionalString($field, 64);
        if ($raw === null || $raw === '') {
            return null;
        }

        if (preg_match(self::CODE_SHAPE, $raw) !== 1) {
            $v->fail($field, 'Use letters, digits, hyphens or underscores only.');

            return null;
        }

        return strtolower($raw);
    }

    private static function ruleKey(Validator $v, bool $required = true): ?string
    {
        $raw = $required ? $v->requiredString('rule_key', 64) : $v->optionalString('rule_key', 64);
        if ($raw === null || $raw === '') {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $raw) !== 1) {
            $v->fail('rule_key', 'Use a lowercase key starting with a letter, such as liability_uncapped.');

            return null;
        }

        return $raw;
    }

    /**
     * JSON for a jsonb column, or null when the caller never mentioned the
     * field — which the statements above read as "leave the stored list alone".
     */
    private static function jsonOrNull(Validator $v, string $field, int $maxItems = 200): ?string
    {
        if (! $v->has($field)) {
            return null;
        }

        return json_encode($v->optionalArray($field, $maxItems), JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * A PHP bool as PostgreSQL sees it.
     *
     * Passed as text against an explicit cast in the statement rather than
     * bound as a bool: PDO's boolean binding differs between drivers, and a
     * settings flag that silently becomes false is not a bug anyone looks for.
     */
    private static function pgBool(?bool $value): ?string
    {
        return $value === null ? null : ($value ? 'true' : 'false');
    }

    private static function asDuplicate(PDOException $e, string $field, string $message): \RuntimeException
    {
        return $e->getCode() === '23505' ? new ValidationFailed([$field => $message]) : $e;
    }

    /** @return array<string,mixed> */
    private function aiStatus(): array
    {
        $base = ['configured' => false, 'provider' => null, 'model' => null, 'message' => null];

        if (class_exists(AiProviderFactory::class)) {
            try {
                $status = AiProviderFactory::status();
                $base   = [
                    'configured' => (bool) ($status['configured'] ?? false),
                    'provider'   => $status['provider'] ?? null,
                    'model'      => $status['model'] ?? null,
                    'message'    => $status['message'] ?? null,
                ];
            } catch (Throwable $e) {
                error_log('[contracts][settings] AI status unavailable: ' . $e->getMessage());
            }
        }

        $base['disclaimer'] = SessionController::AI_DISCLAIMER;

        return $base;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateSettings(array $row): array
    {
        foreach (['number_include_year', 'number_reset_yearly', 'ai_enabled', 'ai_auto_extract', 'ai_auto_risk'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = ContractService::toBool($row[$flag]);
            }
        }
        foreach (['id', 'cmp_id', 'number_pad', 'default_notice_days', 'approval_escalation_days'] as $number) {
            if (isset($row[$number])) {
                $row[$number] = (int) $row[$number];
            }
        }
        $row['settings_json'] = self::decode($row['settings_json'] ?? null, []);

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateType(array $row): array
    {
        foreach (['is_system', 'is_active'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = ContractService::toBool($row[$flag]);
            }
        }
        foreach (['id', 'sort_order'] as $number) {
            if (isset($row[$number])) {
                $row[$number] = (int) $row[$number];
            }
        }
        $row['required_fields']   = self::decode($row['required_fields'] ?? null, []);
        $row['mandatory_clauses'] = self::decode($row['mandatory_clauses'] ?? null, []);

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateDepartment(array $row): array
    {
        if (array_key_exists('is_active', $row)) {
            $row['is_active'] = ContractService::toBool($row['is_active']);
        }
        if (isset($row['id'])) {
            $row['id'] = (int) $row['id'];
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateCustomField(array $row): array
    {
        foreach (['is_required', 'is_filterable', 'is_active'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = ContractService::toBool($row[$flag]);
            }
        }
        if (isset($row['id'])) {
            $row['id'] = (int) $row['id'];
        }
        $row['options'] = self::decode($row['options'] ?? null, []);

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateRiskRule(array $row): array
    {
        foreach (['is_system', 'is_active'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = ContractService::toBool($row[$flag]);
            }
        }
        foreach (['id', 'score_weight'] as $number) {
            if (isset($row[$number])) {
                $row[$number] = (int) $row[$number];
            }
        }
        $row['value_list']       = self::decode($row['value_list'] ?? null, []);
        $row['applies_to_types'] = self::decode($row['applies_to_types'] ?? null, []);

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateView(array $row): array
    {
        foreach (['is_shared', 'is_default'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = ContractService::toBool($row[$flag]);
            }
        }
        if (isset($row['id'])) {
            $row['id'] = (int) $row['id'];
        }
        $row['filters'] = self::decode($row['filters'] ?? null, []);
        $row['columns'] = self::decode($row['columns'] ?? null, []);

        return $row;
    }

    /** @param array<string,mixed>|list<mixed> $fallback @return array<string,mixed>|list<mixed> */
    private static function decode(mixed $raw, array $fallback): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return $fallback;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $fallback;
    }
}
