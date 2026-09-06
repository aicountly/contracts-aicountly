<?php

declare(strict_types=1);

/**
 * The Contracts API surface.
 *
 * Deployed at `<document root>/api`, so every path here is reachable as both
 * `/api/<path>` and `/<path>` — the router tries both forms because which one
 * Apache hands over depends on how the rewrite fired.
 *
 * Route order matters: a literal segment must be declared before the pattern
 * that would also match it, or `/contracts/export` is read as a contract with
 * the id "export".
 */

use App\Core\Router;

$router = Router::getInstance();

$router->get('/', 'Api\HealthController@index');
$router->get('/health', 'Api\HealthController@index');

$router->group('/api', function (Router $r): void {
    $r->get('/health', 'Api\HealthController@index');

    // --- Session and company context ------------------------------------
    // The two /manage reads answer before a company is selected — they are
    // what the SPA uses to select one.
    $r->get('/me', 'Api\SessionController@me');
    $r->get('/manage/companies', 'Api\ManageProxyController@companies');
    $r->get('/manage/company', 'Api\ManageProxyController@company');

    // --- Dashboard -------------------------------------------------------
    $r->get('/dashboard/kpis', 'Api\DashboardController@kpis');
    $r->get('/dashboard/charts', 'Api\DashboardController@charts');
    $r->get('/dashboard/my-actions', 'Api\DashboardController@myActions');
    $r->get('/dashboard/activity', 'Api\DashboardController@activity');

    // --- Search ----------------------------------------------------------
    $r->get('/search', 'Api\SearchController@index');

    // --- Contracts -------------------------------------------------------
    $r->get('/contracts/export', 'Api\ContractController@export');
    $r->get('/contracts', 'Api\ContractController@index');
    $r->post('/contracts', 'Api\ContractController@store');
    $r->get('/contracts/{id}', 'Api\ContractController@show');
    $r->put('/contracts/{id}', 'Api\ContractController@update');
    $r->delete('/contracts/{id}', 'Api\ContractController@destroy');
    $r->post('/contracts/{id}/status', 'Api\ContractController@changeStatus');
    $r->post('/contracts/{id}/archive', 'Api\ContractController@archive');
    $r->post('/contracts/{id}/favourite', 'Api\ContractController@favourite');
    $r->get('/contracts/{id}/activity', 'Api\ContractController@activity');
    $r->get('/contracts/{id}/audit', 'Api\ContractController@audit');

    // --- Parties and counterparties --------------------------------------
    $r->get('/counterparties/search', 'Api\PartyController@searchContacts');
    $r->get('/contracts/{id}/parties', 'Api\PartyController@index');
    $r->post('/contracts/{id}/parties', 'Api\PartyController@store');
    $r->post('/contracts/{id}/parties/snapshot-all', 'Api\PartyController@snapshotAll');
    $r->put('/parties/{id}', 'Api\PartyController@update');
    $r->delete('/parties/{id}', 'Api\PartyController@destroy');
    $r->post('/parties/{id}/snapshot', 'Api\PartyController@snapshot');
    $r->get('/parties/{id}/snapshots', 'Api\PartyController@snapshots');

    // --- Documents, versions, uploads ------------------------------------
    $r->get('/contracts/{id}/documents', 'Api\DocumentController@index');
    $r->get('/contracts/{id}/compare', 'Api\DocumentController@compare');
    $r->post('/uploads/sessions', 'Api\DocumentController@createUploadSession');
    $r->post('/uploads/sessions/{id}/complete', 'Api\DocumentController@completeUpload');
    $r->post('/uploads/sessions/{id}/finalize', 'Api\DocumentController@finalizeUpload');
    $r->post('/uploads/sessions/{id}/abort', 'Api\DocumentController@abortUpload');
    $r->post('/uploads/direct', 'Api\DocumentController@directUpload');
    $r->post('/documents/link', 'Api\DocumentController@linkDriveFile');
    $r->get('/versions/{id}/url', 'Api\DocumentController@versionUrl');
    $r->get('/versions/{id}/text', 'Api\DocumentController@versionText');
    $r->post('/versions/{id}/executed', 'Api\DocumentController@markExecuted');
    $r->delete('/versions/{id}', 'Api\DocumentController@destroyVersion');

    // --- Contract requests -----------------------------------------------
    $r->get('/requests', 'Api\RequestController@index');
    $r->post('/requests', 'Api\RequestController@store');
    $r->get('/requests/{id}', 'Api\RequestController@show');
    $r->put('/requests/{id}', 'Api\RequestController@update');
    $r->post('/requests/{id}/submit', 'Api\RequestController@submit');
    $r->post('/requests/{id}/decision', 'Api\RequestController@decision');
    $r->post('/requests/{id}/convert', 'Api\RequestController@convert');

    // --- Approvals -------------------------------------------------------
    $r->get('/approvals/queue', 'Api\ApprovalController@queue');
    $r->get('/approvals/instances', 'Api\ApprovalController@instances');
    $r->post('/approvals/submit', 'Api\ApprovalController@submit');
    $r->post('/approvals/{id}/act', 'Api\ApprovalController@act');
    $r->post('/approvals/{id}/cancel', 'Api\ApprovalController@cancel');
    $r->get('/approval-workflows', 'Api\ApprovalController@workflows');
    $r->post('/approval-workflows', 'Api\ApprovalController@storeWorkflow');
    $r->put('/approval-workflows/{id}', 'Api\ApprovalController@updateWorkflow');
    $r->delete('/approval-workflows/{id}', 'Api\ApprovalController@destroyWorkflow');

    // --- Obligations and milestones --------------------------------------
    $r->get('/obligations', 'Api\ObligationController@index');
    $r->get('/contracts/{id}/obligations', 'Api\ObligationController@forContract');
    $r->post('/contracts/{id}/obligations', 'Api\ObligationController@store');
    $r->put('/obligations/{id}', 'Api\ObligationController@update');
    $r->delete('/obligations/{id}', 'Api\ObligationController@destroy');
    $r->post('/obligations/{id}/generate', 'Api\ObligationController@generate');
    $r->post('/occurrences/{id}/complete', 'Api\ObligationController@completeOccurrence');
    $r->post('/occurrences/{id}/status', 'Api\ObligationController@occurrenceStatus');
    $r->get('/contracts/{id}/milestones', 'Api\ObligationController@milestones');
    $r->post('/contracts/{id}/milestones', 'Api\ObligationController@storeMilestone');
    $r->put('/milestones/{id}', 'Api\ObligationController@updateMilestone');
    $r->delete('/milestones/{id}', 'Api\ObligationController@destroyMilestone');
    $r->post('/milestones/{id}/complete', 'Api\ObligationController@completeMilestone');

    // --- Commercials -----------------------------------------------------
    $r->get('/contracts/{id}/commercials', 'Api\CommercialController@show');
    $r->put('/contracts/{id}/commercials', 'Api\CommercialController@update');
    $r->post('/contracts/{id}/payment-schedules', 'Api\CommercialController@storeSchedule');
    $r->put('/payment-schedules/{id}', 'Api\CommercialController@updateSchedule');
    $r->delete('/payment-schedules/{id}', 'Api\CommercialController@destroySchedule');

    // --- Renewals, amendments, terminations ------------------------------
    $r->get('/renewals', 'Api\LifecycleController@renewalPipeline');
    $r->get('/contracts/{id}/renewals', 'Api\LifecycleController@renewalsForContract');
    $r->post('/contracts/{id}/renewals/ensure', 'Api\LifecycleController@ensureRenewalCycle');
    $r->post('/renewals/{id}/decision', 'Api\LifecycleController@renewalDecision');
    $r->post('/renewals/{id}/recommend', 'Api\AiController@renewalAdvice');

    $r->get('/amendments', 'Api\LifecycleController@amendmentRegister');
    $r->get('/contracts/{id}/amendments', 'Api\LifecycleController@amendmentsForContract');
    $r->post('/contracts/{id}/amendments', 'Api\LifecycleController@storeAmendment');
    $r->get('/contracts/{id}/effective-position', 'Api\LifecycleController@effectivePosition');
    $r->put('/amendments/{id}', 'Api\LifecycleController@updateAmendment');
    $r->delete('/amendments/{id}', 'Api\LifecycleController@destroyAmendment');
    $r->post('/amendments/{id}/apply', 'Api\LifecycleController@applyAmendment');

    $r->get('/contracts/{id}/terminations', 'Api\LifecycleController@terminationsForContract');
    $r->post('/contracts/{id}/terminations', 'Api\LifecycleController@storeTermination');
    $r->put('/terminations/{id}', 'Api\LifecycleController@updateTermination');
    $r->post('/terminations/{id}/approve', 'Api\LifecycleController@approveTermination');
    $r->post('/terminations/{id}/notice', 'Api\LifecycleController@issueTerminationNotice');
    $r->post('/terminations/{id}/complete', 'Api\LifecycleController@completeTermination');

    // --- Risk, clauses, playbook -----------------------------------------
    $r->get('/risks', 'Api\RiskController@portfolio');
    $r->get('/contracts/{id}/risk', 'Api\RiskController@show');
    $r->post('/contracts/{id}/risk/assess', 'Api\RiskController@assess');
    $r->get('/contracts/{id}/health', 'Api\RiskController@health');
    $r->post('/risk-findings/{id}/review', 'Api\RiskController@reviewFinding');

    $r->get('/contracts/{id}/clauses', 'Api\ClauseController@forContract');
    $r->post('/contracts/{id}/clauses', 'Api\ClauseController@storeForContract');
    $r->put('/contract-clauses/{id}', 'Api\ClauseController@updateForContract');
    $r->delete('/contract-clauses/{id}', 'Api\ClauseController@destroyForContract');
    $r->get('/contracts/{id}/deviations', 'Api\ClauseController@deviations');
    $r->post('/contracts/{id}/deviations/evaluate', 'Api\ClauseController@evaluateDeviations');
    $r->post('/deviations/{id}/review', 'Api\ClauseController@reviewDeviation');

    $r->get('/clause-categories', 'Api\ClauseController@categories');
    $r->get('/clauses', 'Api\ClauseController@index');
    $r->post('/clauses', 'Api\ClauseController@store');
    $r->get('/clauses/{id}/versions', 'Api\ClauseController@versions');
    $r->put('/clauses/{id}', 'Api\ClauseController@update');
    $r->delete('/clauses/{id}', 'Api\ClauseController@destroy');

    $r->get('/playbooks', 'Api\ClauseController@playbooks');
    $r->get('/playbooks/{id}/rules', 'Api\ClauseController@playbookRules');
    $r->post('/playbooks/{id}/rules', 'Api\ClauseController@storePlaybookRule');
    $r->put('/playbook-rules/{id}', 'Api\ClauseController@updatePlaybookRule');
    $r->delete('/playbook-rules/{id}', 'Api\ClauseController@destroyPlaybookRule');

    // --- Templates -------------------------------------------------------
    $r->get('/template-variables', 'Api\TemplateController@variables');
    $r->get('/templates', 'Api\TemplateController@index');
    $r->post('/templates', 'Api\TemplateController@store');
    $r->get('/templates/{id}', 'Api\TemplateController@show');
    $r->put('/templates/{id}', 'Api\TemplateController@update');
    $r->delete('/templates/{id}', 'Api\TemplateController@destroy');
    $r->post('/templates/{id}/preview', 'Api\TemplateController@preview');
    $r->post('/templates/{id}/create-contract', 'Api\TemplateController@createContract');

    // --- AI --------------------------------------------------------------
    $r->get('/ai/status', 'Api\AiController@status');
    $r->get('/ai/jobs', 'Api\AiController@jobs');
    $r->get('/ai/review-queue', 'Api\AiController@reviewQueue');
    $r->post('/ai/import', 'Api\AiController@import');
    $r->get('/ai/jobs/{id}', 'Api\AiController@job');
    $r->post('/ai/jobs/{id}/retry', 'Api\AiController@retryJob');
    $r->post('/ai/extractions/{id}/accept', 'Api\AiController@acceptExtraction');
    $r->post('/ai/extractions/{id}/reject', 'Api\AiController@rejectExtraction');
    $r->get('/ai/conversations/{id}/messages', 'Api\AiController@messages');
    $r->post('/ai/contracts/{id}/extract', 'Api\AiController@extract');
    $r->post('/ai/contracts/{id}/summarize', 'Api\AiController@summarize');
    $r->get('/ai/contracts/{id}/summary', 'Api\AiController@summary');
    $r->put('/ai/contracts/{id}/summary', 'Api\AiController@editSummary');
    $r->post('/ai/contracts/{id}/ask', 'Api\AiController@ask');
    $r->get('/ai/contracts/{id}/conversations', 'Api\AiController@conversations');
    $r->post('/ai/contracts/{id}/renewal-advice', 'Api\AiController@renewalAdvice');
    $r->post('/ai/contracts/{id}/apply-verified', 'Api\AiController@applyVerified');
    $r->get('/ai/insights', 'Api\AiController@insights');

    // --- Reports ---------------------------------------------------------
    $r->get('/reports', 'Api\ReportController@definitions');
    $r->get('/reports/{key}/export', 'Api\ReportController@export');
    $r->get('/reports/{key}', 'Api\ReportController@show');

    // --- Notifications, comments, links ----------------------------------
    $r->get('/notifications', 'Api\NotificationController@index');
    $r->post('/notifications/read-all', 'Api\NotificationController@readAll');
    $r->post('/notifications/{id}/read', 'Api\NotificationController@read');

    $r->get('/contracts/{id}/comments', 'Api\CommentController@index');
    $r->post('/contracts/{id}/comments', 'Api\CommentController@store');
    $r->put('/comments/{id}', 'Api\CommentController@update');
    $r->delete('/comments/{id}', 'Api\CommentController@destroy');
    $r->post('/comments/{id}/resolve', 'Api\CommentController@resolve');

    $r->get('/contracts/{id}/links', 'Api\LinkedRecordController@index');
    $r->post('/contracts/{id}/links', 'Api\LinkedRecordController@store');
    $r->delete('/links/{id}', 'Api\LinkedRecordController@destroy');

    // --- Signatures ------------------------------------------------------
    $r->get('/contracts/{id}/signatures', 'Api\SignatureController@index');
    $r->post('/contracts/{id}/signatures', 'Api\SignatureController@store');
    $r->post('/signatures/{id}/send', 'Api\SignatureController@send');
    $r->post('/signatures/{id}/cancel', 'Api\SignatureController@cancel');
    $r->post('/signatures/{id}/mark-signed', 'Api\SignatureController@markSigned');

    // Unauthenticated by necessity — the provider has no session. Verified by
    // its own signature instead, and every delivery is stored before it is
    // acted on so a retry is a no-op.
    $r->post('/webhooks/signature/{provider}', 'Api\SignatureController@webhook');

    // --- Settings --------------------------------------------------------
    $r->get('/settings', 'Api\SettingsController@index');
    $r->put('/settings', 'Api\SettingsController@update');
    $r->get('/settings/contract-types', 'Api\SettingsController@contractTypes');
    $r->post('/settings/contract-types', 'Api\SettingsController@storeContractType');
    $r->put('/contract-types/{id}', 'Api\SettingsController@updateContractType');
    $r->delete('/contract-types/{id}', 'Api\SettingsController@destroyContractType');
    $r->get('/settings/departments', 'Api\SettingsController@departments');
    $r->post('/settings/departments', 'Api\SettingsController@storeDepartment');
    $r->put('/departments/{id}', 'Api\SettingsController@updateDepartment');
    $r->delete('/departments/{id}', 'Api\SettingsController@destroyDepartment');
    $r->get('/settings/custom-fields', 'Api\SettingsController@customFields');
    $r->post('/settings/custom-fields', 'Api\SettingsController@storeCustomField');
    $r->put('/custom-fields/{id}', 'Api\SettingsController@updateCustomField');
    $r->delete('/custom-fields/{id}', 'Api\SettingsController@destroyCustomField');
    $r->get('/settings/tags', 'Api\SettingsController@tags');
    $r->post('/settings/tags', 'Api\SettingsController@storeTag');
    $r->delete('/tags/{id}', 'Api\SettingsController@destroyTag');
    $r->get('/settings/roles', 'Api\SettingsController@roles');
    $r->post('/settings/roles/grant', 'Api\SettingsController@grantRole');
    $r->post('/settings/roles/revoke', 'Api\SettingsController@revokeRole');
    $r->get('/settings/risk-rules', 'Api\SettingsController@riskRules');
    $r->post('/settings/risk-rules', 'Api\SettingsController@storeRiskRule');
    $r->put('/risk-rules/{id}', 'Api\SettingsController@updateRiskRule');
    $r->delete('/risk-rules/{id}', 'Api\SettingsController@destroyRiskRule');
    $r->get('/settings/integrations', 'Api\SettingsController@integrations');
    $r->get('/settings/saved-views', 'Api\SettingsController@savedViews');
    $r->post('/settings/saved-views', 'Api\SettingsController@storeSavedView');
    $r->delete('/saved-views/{id}', 'Api\SettingsController@destroySavedView');

    // --- Portal auth relay ----------------------------------------------
    // The SPA's session bootstrap. An allow-list, not a proxy: forwarding
    // arbitrary portal paths would make this host an open relay for the whole
    // auth surface, with the portal seeing this server's IP instead of the
    // caller's.
    $r->post('/global/{path}', 'Api\AuthRelayController@relay');
    $r->get('/session', 'Api\AuthRelayController@session');
});
