<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Support\TenantContext;
use PDO;
use Throwable;

/**
 * In-app notifications, and the honest state of every other channel.
 *
 * Two rules shape this class.
 *
 * The first is deduplication. Almost every notification this product sends
 * comes from a nightly sweep, and cPanel cron is not exactly-once: an operator
 * re-runs a task, a schedule fires twice, a timeout is retried. A `dedupe_key`
 * plus the recipient carries a unique index (`uq_notification_dedupe`), so
 * "your cancellation window closes in 30 days" is written once however many
 * times the sweep runs. notify() returns null for the duplicate rather than
 * throwing, because to the sweep a notification already delivered is a success.
 *
 * The second is that a channel either works or says so. In-app is written here
 * and always works. Email goes through {@see EmailChannel}, and with no
 * transport configured the null channel does nothing and reports that it did
 * nothing — `email_sent_at` stays null and channelStatus() says why. Claiming a
 * mail was sent when nothing left the server is worse than not sending it: it
 * turns "nobody told me the contract was renewing" into an argument about logs.
 */
final class NotificationService
{
    /** Mirrors ck_notification_severity. */
    public const SEVERITIES = ['info', 'success', 'warning', 'critical'];

    private static ?EmailChannel $emailChannel = null;

    public function __construct(private PDO $pdo)
    {
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * Write one in-app notification, and attempt email where it is configured.
     *
     * Takes an environment and cmp_id rather than a TenantContext because the
     * callers are sweeps: they run with no signed-in user and notify people
     * across every company in one pass.
     *
     * @param array{contract_id?: int|null, link_path?: string|null, severity?: string,
     *              dedupe_key?: string|null, metadata?: array<string,mixed>,
     *              email?: string|null} $opts
     * @return int|null the notification id, or null when an identical one already
     *                  exists for this recipient
     */
    public function notify(
        string $environment,
        int $cmpId,
        string $recipientUuid,
        string $eventType,
        string $title,
        ?string $body = null,
        array $opts = []
    ): ?int {
        $recipient = trim($recipientUuid);
        if ($recipient === '') {
            return null;
        }

        $severity = in_array($opts['severity'] ?? 'info', self::SEVERITIES, true)
            ? (string) $opts['severity']
            : 'info';

        $dedupe = isset($opts['dedupe_key']) && is_string($opts['dedupe_key']) && trim($opts['dedupe_key']) !== ''
            ? mb_substr(trim($opts['dedupe_key']), 0, 160)
            : null;

        $metadata = isset($opts['metadata']) && is_array($opts['metadata']) ? $opts['metadata'] : [];

        // The unique index is partial (dedupe_key IS NOT NULL), so its
        // predicate is repeated here for PostgreSQL to infer the arbiter.
        $st = $this->pdo->prepare(
            'INSERT INTO contract_notifications
             (environment, cmp_id, recipient_uuid, event_type, title, body, severity,
              contract_id, link_path, metadata, dedupe_key)
             VALUES (:env, :cmp, :recipient, :event, :title, :body, :severity,
                     :contract, :link, :meta::jsonb, :dedupe)
             ON CONFLICT (environment, cmp_id, recipient_uuid, dedupe_key)
                 WHERE dedupe_key IS NOT NULL
                 DO NOTHING
             RETURNING id'
        );
        $st->execute([
            'env'       => $environment,
            'cmp'       => $cmpId,
            'recipient' => mb_substr($recipient, 0, 64),
            'event'     => mb_substr($eventType, 0, 64),
            'title'     => mb_substr($title, 0, 255),
            'body'      => $body,
            'severity'  => $severity,
            'contract'  => isset($opts['contract_id']) && $opts['contract_id'] !== null ? (int) $opts['contract_id'] : null,
            'link'      => isset($opts['link_path']) && is_string($opts['link_path'])
                ? mb_substr($opts['link_path'], 0, 255)
                : null,
            'meta'      => json_encode($metadata, JSON_UNESCAPED_SLASHES) ?: '{}',
            'dedupe'    => $dedupe,
        ]);

        $id = $st->fetchColumn();
        if ($id === false) {
            return null;
        }

        $this->dispatchEmail((int) $id, $recipient, $title, $body, $opts);

        return (int) $id;
    }

    /**
     * The same notification to several people.
     *
     * Written one row at a time rather than as one multi-row INSERT: the dedupe
     * index is per recipient, so a batch insert would have to decide what to do
     * when three of five recipients already have the notice, and "skip the ones
     * that exist" is exactly what ON CONFLICT DO NOTHING per row already does.
     *
     * @param list<string> $recipientUuids
     * @param array<string,mixed> $opts
     * @return int how many were actually written
     */
    public function notifyMany(
        string $environment,
        int $cmpId,
        array $recipientUuids,
        string $eventType,
        string $title,
        ?string $body = null,
        array $opts = []
    ): int {
        $written = 0;
        foreach (array_unique($recipientUuids) as $uuid) {
            if (! is_string($uuid)) {
                continue;
            }
            if ($this->notify($environment, $cmpId, $uuid, $eventType, $title, $body, $opts) !== null) {
                $written++;
            }
        }

        return $written;
    }

    // -----------------------------------------------------------------------
    // Reading — always the caller's own inbox
    // -----------------------------------------------------------------------

    /**
     * A page of the signed-in user's notifications.
     *
     * The recipient is taken from the TenantContext and never from a filter. A
     * notification names the contract it is about, so reading someone else's
     * inbox would be a way to learn which agreements they are working on.
     *
     * @param array{unread_only?: bool, severity?: string, event_type?: string,
     *              contract_id?: int} $filters
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listFor(TenantContext $ctx, array $filters, int $limit, int $offset): array
    {
        // Every clause is qualified with `n.` because the row query joins
        // contracts, and `environment` alone is ambiguous across those two.
        $clauses = ['n.environment = :env', 'n.cmp_id = :cmp', 'n.recipient_uuid = :me'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId, 'me' => $ctx->uuid];

        if (! empty($filters['unread_only'])) {
            $clauses[] = 'n.read_at IS NULL';
        }
        if (isset($filters['severity']) && in_array($filters['severity'], self::SEVERITIES, true)) {
            $clauses[]           = 'n.severity = :sev';
            $params['sev']       = $filters['severity'];
        }
        if (! empty($filters['event_type']) && is_string($filters['event_type'])) {
            $clauses[]           = 'n.event_type = :event';
            $params['event']     = mb_substr($filters['event_type'], 0, 64);
        }
        if (! empty($filters['contract_id'])) {
            $clauses[]           = 'n.contract_id = :contract';
            $params['contract']  = (int) $filters['contract_id'];
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);

        $countSt = $this->pdo->prepare("SELECT COUNT(*) FROM contract_notifications n {$where}");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $st = $this->pdo->prepare(
            "SELECT n.id, n.uuid, n.event_type, n.title, n.body, n.severity, n.contract_id,
                    n.link_path, n.metadata, n.read_at, n.email_sent_at, n.created_at,
                    c.contract_number, c.title AS contract_title
             FROM contract_notifications n
             LEFT JOIN contracts c ON c.id = n.contract_id
             {$where}
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', max(1, min(200, $limit)), PDO::PARAM_INT);
        $st->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
        $st->execute();

        $items = array_map(static function (array $row): array {
            $row['id']          = (int) $row['id'];
            $row['contract_id'] = $row['contract_id'] === null ? null : (int) $row['contract_id'];
            $row['is_read']     = $row['read_at'] !== null;
            $decoded            = is_string($row['metadata']) ? json_decode($row['metadata'], true) : $row['metadata'];
            $row['metadata']    = is_array($decoded) ? $decoded : [];

            return $row;
        }, $st->fetchAll() ?: []);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Mark one notification read.
     *
     * False means "not yours or not there" — the same answer for both, so a
     * caller cannot walk ids to learn which ones exist.
     */
    public function markRead(TenantContext $ctx, int $id): bool
    {
        $st = $this->pdo->prepare(
            'UPDATE contract_notifications
             SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
             WHERE id = ? AND environment = ? AND cmp_id = ? AND recipient_uuid = ?'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId, $ctx->uuid]);

        return $st->rowCount() > 0;
    }

    /** @return int how many were unread before this call */
    public function markAllRead(TenantContext $ctx): int
    {
        $st = $this->pdo->prepare(
            'UPDATE contract_notifications
             SET read_at = CURRENT_TIMESTAMP
             WHERE environment = ? AND cmp_id = ? AND recipient_uuid = ? AND read_at IS NULL'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $ctx->uuid]);

        return $st->rowCount();
    }

    public function unreadCount(TenantContext $ctx): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM contract_notifications
             WHERE environment = ? AND cmp_id = ? AND recipient_uuid = ? AND read_at IS NULL'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $ctx->uuid]);

        return (int) $st->fetchColumn();
    }

    public function delete(TenantContext $ctx, int $id): bool
    {
        $st = $this->pdo->prepare(
            'DELETE FROM contract_notifications
             WHERE id = ? AND environment = ? AND cmp_id = ? AND recipient_uuid = ?'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId, $ctx->uuid]);

        return $st->rowCount() > 0;
    }

    // -----------------------------------------------------------------------
    // Channels
    // -----------------------------------------------------------------------

    /**
     * What each channel can actually do right now.
     *
     * Reported to /api/health and to the settings screen so an administrator
     * can see that email is off before a renewal deadline passes unannounced,
     * rather than afterwards.
     *
     * @return array{in_app: array{enabled: bool}, email: array{enabled: bool, configured: bool, transport: string, reason: string}}
     */
    public function channelStatus(): array
    {
        $enabled = Env::bool('CONTRACTS_EMAIL_ENABLED', false);
        $channel = self::emailChannel();
        $ready   = $channel !== null && $channel->isConfigured();

        return [
            'in_app' => ['enabled' => true],
            'email'  => [
                'enabled'    => $enabled && $ready,
                'configured' => $ready,
                'transport'  => $channel?->describe() ?? 'none',
                'reason'     => match (true) {
                    ! $enabled => 'CONTRACTS_EMAIL_ENABLED is not set to true.',
                    ! $ready   => 'No email transport is configured; in-app notifications are still delivered.',
                    default    => '',
                },
            ],
        ];
    }

    /**
     * Install an email transport.
     *
     * The only way one gets here. Contracts ships no SMTP client and invents no
     * credentials: a deployment that wants email supplies a transport at boot,
     * and until it does the email column on every notification stays null and
     * says so.
     */
    public static function setEmailChannel(?EmailChannel $channel): void
    {
        self::$emailChannel = $channel;
    }

    public static function emailChannel(): ?EmailChannel
    {
        return self::$emailChannel;
    }

    /**
     * Attempt the email copy, and record it only if it was really sent.
     *
     * A failure here never propagates: the in-app notification is already
     * written and is the channel this product guarantees. Losing it because a
     * mail server timed out would be trading the reliable half for the
     * unreliable one.
     *
     * @audit-unscoped the id is the RETURNING value of the insert three lines
     *                 above the only call site — it is this method's own row,
     *                 never a caller's, so there is nothing for a cmp_id filter
     *                 to protect against.
     *
     * @param array<string,mixed> $opts
     */
    private function dispatchEmail(int $notificationId, string $recipient, string $title, ?string $body, array $opts): void
    {
        if (! Env::bool('CONTRACTS_EMAIL_ENABLED', false)) {
            return;
        }

        $channel = self::$emailChannel;
        if ($channel === null || ! $channel->isConfigured()) {
            return;
        }

        // Contracts holds a user's uuid, not their address — identity lives in
        // the portal. A caller that has already resolved one passes it in;
        // without it there is nobody to write to, and guessing an address from
        // a uuid would send contract details to the wrong person.
        $address = isset($opts['email']) && is_string($opts['email']) ? trim($opts['email']) : '';
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        try {
            $sent = $channel->send($address, $title, $body ?? '', [
                'notification_id' => $notificationId,
                'recipient_uuid'  => $recipient,
                'link_path'       => $opts['link_path'] ?? null,
                'severity'        => $opts['severity'] ?? 'info',
            ]);
        } catch (Throwable $e) {
            error_log('[contracts][notify] email transport failed: ' . $e->getMessage());

            return;
        }

        if ($sent !== true) {
            return;
        }

        $this->pdo->prepare('UPDATE contract_notifications SET email_sent_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$notificationId]);
    }
}

/**
 * A transport that can put a notification in someone's inbox.
 *
 * Declared beside its only consumer, as Core\ResponseSent is: nothing reaches
 * this interface without going through NotificationService, and a file of its
 * own would only be a file nobody opens.
 *
 * An implementation must return false rather than throw when it could not
 * send, and must never report true for a message it merely queued somewhere it
 * cannot answer for.
 */
interface EmailChannel
{
    /** @param array<string,mixed> $context */
    public function send(string $to, string $subject, string $body, array $context = []): bool;

    public function isConfigured(): bool;

    /** Short name of the transport, for the health report. */
    public function describe(): string;
}
