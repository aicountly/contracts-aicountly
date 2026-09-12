<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\ContractsAiProvider;
use App\Ai\PromptGuard;
use App\Ai\Prompts\ContractPrompts;
use App\Ai\Schemas\ExtractionSchema;
use App\Core\Database;
use App\Support\DomainException;
use App\Support\TenantContext;
use App\Support\ValidationFailed;
use PDO;

/**
 * Ask a question about one contract, and get an answer from that contract.
 *
 * Two properties make this feature safe to ship, and both are enforced here
 * rather than asked of the model:
 *
 * **Scope.** Everything the model is shown is fetched by a query filtered on
 * environment, cmp_id and this one contract_id. There is no retrieval step that
 * ranks across a corpus, because a ranking bug in one would let a question
 * about a supplier agreement answer itself out of a different company's
 * settlement. The model cannot leak what it was never given.
 *
 * **Grounding.** The reply carries its own `answered` flag and its citations. A
 * message stored with `grounded = false` is one the contract did not answer,
 * and it is stored and shown as exactly that. "I could not find this in the
 * contract" is a useful answer to a person deciding something; a fluent
 * invention is not, and the two are indistinguishable on screen unless the
 * difference is recorded here.
 */
final class AskContractService
{
    /** What every answer is shown with. The product reports what a contract says; it does not advise. */
    public const DISCLAIMER = 'Generated from the text of this contract only. Check the cited wording before relying on it. This is not legal advice.';

    /** How much contract text one question is answered from. */
    private const CONTEXT_CHARS = 90000;

    /** Turns of the same conversation carried into the next question. */
    private const HISTORY_TURNS = 8;

    private const MAX_QUESTION_CHARS = 2000;

    private AiJobService $jobs;

    private ActivityService $activity;

    public function __construct(private PDO $pdo, ?ContractsAiProvider $provider = null)
    {
        $this->jobs     = new AiJobService($pdo, $provider);
        $this->activity = new ActivityService($pdo);
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    /**
     * Answer one question about one contract.
     *
     * @return array{answer: string, grounded: bool, citations: list<array<string,mixed>>,
     *               disclaimer: string, conversation_id: int, message_id: int}
     */
    public function ask(TenantContext $ctx, int $contractId, string $question, ?int $conversationId = null): array
    {
        $question = trim($question);
        if ($question === '') {
            throw new ValidationFailed(['question' => 'Ask a question about this contract.']);
        }
        if (mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            throw new ValidationFailed(['question' => 'Keep the question under ' . self::MAX_QUESTION_CHARS . ' characters.']);
        }

        $contract = $this->contractOrFail($ctx, $contractId);
        $text     = $this->contractText($ctx, $contractId);
        $clauses  = $this->contractClauses($ctx, $contractId);

        if ($text === '' && $clauses === []) {
            // Deliberately an error rather than an ungrounded answer. "Not
            // stated in this contract" would tell the user we read it and found
            // nothing, when in fact there was nothing to read.
            throw DomainException::conflict(
                'This contract has no readable document text to answer from yet.',
                'DOCUMENT_TEXT_UNAVAILABLE'
            );
        }

        $conversation = $conversationId === null
            ? null
            : $this->conversationOrFail($ctx, $conversationId, $contractId);

        $history = $conversation === null ? [] : $this->recentTurns($ctx, (int) $conversation['id']);

        $reply = $this->jobs->callValidated(
            $ctx,
            $this->jobs->providerOrFail(),
            ContractPrompts::answerQuestion(
                $question,
                PromptGuard::sanitise($text, self::CONTEXT_CHARS),
                $clauses,
                $history
            ),
            ExtractionSchema::answer(),
            'answer',
            ['contract_id' => $contractId, 'schema_name' => 'contract_answer', 'max_tokens' => 2000]
        );

        $value     = is_array($reply['value']) ? $reply['value'] : [];
        $citations = is_array($value['citations'] ?? null) ? array_values($value['citations']) : [];

        // Grounded means both halves: the model said the contract answered the
        // question, and it produced wording to show for it. Either alone is a
        // claim the reader cannot check.
        $grounded = ($value['answered'] ?? false) === true && $citations !== [];
        $answer   = trim((string) ($value['answer'] ?? ''));
        if ($answer === '') {
            $answer = 'This is not stated in this contract.';
            $grounded = false;
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use (
            $ctx,
            $contractId,
            $contract,
            $conversation,
            $question,
            $answer,
            $citations,
            $grounded,
            $reply
        ): array {
            $conversationId = $conversation === null
                ? $this->startConversation($ctx, $contractId, $question)
                : (int) $conversation['id'];

            $this->writeMessage($ctx, $conversationId, 'user', $question, [], true, null);
            $messageId = $this->writeMessage(
                $ctx,
                $conversationId,
                'assistant',
                $answer,
                $citations,
                $grounded,
                $reply
            );

            $pdo->prepare('UPDATE ai_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$conversationId]);

            $this->activity->record($ctx, $contractId, 'ai.question.asked', sprintf(
                'Question asked about %s',
                (string) ($contract['contract_number'] ?? 'this contract')
            ), ['grounded' => $grounded, 'conversation_id' => $conversationId]);

            return [
                'answer'          => $answer,
                'grounded'        => $grounded,
                'citations'       => $citations,
                'disclaimer'      => self::DISCLAIMER,
                'conversation_id' => $conversationId,
                'message_id'      => $messageId,
            ];
        });
    }

    /**
     * This user's conversations, newest first.
     *
     * Scoped to the caller: a conversation is one person's line of questioning,
     * and half of them read as notes to self.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function conversations(TenantContext $ctx, ?int $contractId = null, int $limit = 25, int $offset = 0): array
    {
        $where  = 'WHERE c.environment = :env AND c.cmp_id = :cmp AND c.user_uuid = :user';
        $params = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId, 'user' => $ctx->uuid];

        if ($contractId !== null) {
            $where .= ' AND c.contract_id = :contract';
            $params['contract'] = $contractId;
        }

        $countSt = $this->pdo->prepare("SELECT COUNT(*) FROM ai_conversations c {$where}");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $st = $this->pdo->prepare(
            "SELECT c.id, c.uuid, c.contract_id, c.title, c.scope, c.created_at, c.updated_at,
                    ct.contract_number, ct.title AS contract_title,
                    (SELECT COUNT(*) FROM ai_messages m WHERE m.conversation_id = c.id) AS message_count
             FROM ai_conversations c
             LEFT JOIN contracts ct ON ct.id = c.contract_id
             {$where}
             ORDER BY c.updated_at DESC, c.id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', max(1, min(100, $limit)), PDO::PARAM_INT);
        $st->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
        $st->execute();

        $items = array_map(static function (array $row): array {
            $row['id']            = (int) $row['id'];
            $row['contract_id']   = $row['contract_id'] === null ? null : (int) $row['contract_id'];
            $row['message_count'] = (int) $row['message_count'];

            return $row;
        }, $st->fetchAll() ?: []);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The messages of one conversation, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function messages(TenantContext $ctx, int $conversationId, int $limit = 100, int $offset = 0): array
    {
        // Ownership is checked before the messages are read rather than by
        // filtering the messages themselves: an empty list would otherwise be
        // the answer both for a conversation with no messages and for one
        // belonging to somebody else.
        $this->conversationOrFail($ctx, $conversationId, null);

        $st = $this->pdo->prepare(
            'SELECT id, role, content, citations, grounded, provider, model, latency_ms, created_at
             FROM ai_messages
             WHERE conversation_id = :conversation AND environment = :env AND cmp_id = :cmp
             ORDER BY created_at, id
             LIMIT :lim OFFSET :off'
        );
        $st->bindValue(':conversation', $conversationId, PDO::PARAM_INT);
        $st->bindValue(':env', $ctx->environment);
        $st->bindValue(':cmp', $ctx->cmpId, PDO::PARAM_INT);
        $st->bindValue(':lim', max(1, min(500, $limit)), PDO::PARAM_INT);
        $st->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
        $st->execute();

        return array_map(static function (array $row): array {
            $row['id']        = (int) $row['id'];
            $row['grounded']  = ContractService::toBool($row['grounded']);
            $decoded          = is_string($row['citations']) ? json_decode($row['citations'], true) : $row['citations'];
            $row['citations'] = is_array($decoded) ? $decoded : [];

            return $row;
        }, $st->fetchAll() ?: []);
    }

    // -----------------------------------------------------------------------
    // Retrieval — the tenant and contract boundary
    // -----------------------------------------------------------------------

    /**
     * The text of this contract's most recent document version.
     *
     * The join through contract_documents is what ties a version to a contract.
     * Filtering the versions table on its own tenant columns would still allow
     * a version belonging to a different contract in the same company, and a
     * question about one agreement answered out of another is the same class of
     * failure as answering out of another company's — smaller blast radius,
     * identical mechanism.
     */
    private function contractText(TenantContext $ctx, int $contractId): string
    {
        $st = $this->pdo->prepare(
            'SELECT v.extracted_text
             FROM contract_document_versions v
             JOIN contract_documents d ON d.id = v.document_id
             WHERE d.contract_id = ? AND v.environment = ? AND v.cmp_id = ?
               AND v.extracted_text IS NOT NULL AND v.extracted_text <> \'\'
             ORDER BY v.created_at DESC, v.id DESC
             LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        $text = $st->fetchColumn();

        return is_string($text) ? trim($text) : '';
    }

    /** @return list<array<string,mixed>> */
    private function contractClauses(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT clause_number, heading FROM contract_clauses
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY id
             LIMIT 200'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        return $st->fetchAll() ?: [];
    }

    /** @return array<string,mixed> */
    private function contractOrFail(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, contract_number, title FROM contracts
             WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Contract not found.');
        }

        return $row;
    }

    /**
     * The conversation, if it is this user's and about this contract.
     *
     * A mismatched contract is a not-found rather than a validation error: the
     * request is asking to continue a thread about a different agreement, and
     * answering it would attach one contract's answer to another's history.
     *
     * @return array<string,mixed>
     */
    private function conversationOrFail(TenantContext $ctx, int $conversationId, ?int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM ai_conversations
             WHERE id = ? AND environment = ? AND cmp_id = ? AND user_uuid = ?
             LIMIT 1'
        );
        $st->execute([$conversationId, $ctx->environment, $ctx->cmpId, $ctx->uuid]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Conversation not found.');
        }

        if ($contractId !== null && (int) $row['contract_id'] !== $contractId) {
            throw DomainException::notFound('Conversation not found.');
        }

        return $row;
    }

    /**
     * Earlier turns of this conversation, oldest first.
     *
     * Only the text is carried forward, never the citations: they are evidence
     * shown to the user, and repeating them into the prompt invites the model
     * to cite a passage it was quoted rather than one it read.
     *
     * @return list<array{role: string, content: string}>
     */
    private function recentTurns(TenantContext $ctx, int $conversationId): array
    {
        $st = $this->pdo->prepare(
            'SELECT role, content FROM ai_messages
             WHERE conversation_id = :conversation AND environment = :env AND cmp_id = :cmp
               AND role IN (\'user\', \'assistant\')
             ORDER BY created_at DESC, id DESC
             LIMIT :lim'
        );
        $st->bindValue(':conversation', $conversationId, PDO::PARAM_INT);
        $st->bindValue(':env', $ctx->environment);
        $st->bindValue(':cmp', $ctx->cmpId, PDO::PARAM_INT);
        $st->bindValue(':lim', self::HISTORY_TURNS, PDO::PARAM_INT);
        $st->execute();

        $rows = array_reverse($st->fetchAll() ?: []);

        return array_map(static fn (array $r): array => [
            'role'    => (string) $r['role'],
            'content' => (string) $r['content'],
        ], $rows);
    }

    private function startConversation(TenantContext $ctx, int $contractId, string $question): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO ai_conversations (environment, cmp_id, contract_id, user_uuid, title, scope)
             VALUES (?, ?, ?, ?, ?, \'contract\')
             RETURNING id'
        );
        $st->execute([
            $ctx->environment,
            $ctx->cmpId,
            $contractId,
            $ctx->uuid,
            mb_substr($question, 0, 255),
        ]);

        return (int) $st->fetchColumn();
    }

    /**
     * @param list<array<string,mixed>>  $citations
     * @param array<string,mixed>|null   $reply
     */
    private function writeMessage(
        TenantContext $ctx,
        int $conversationId,
        string $role,
        string $content,
        array $citations,
        bool $grounded,
        ?array $reply
    ): int {
        $st = $this->pdo->prepare(
            'INSERT INTO ai_messages
             (conversation_id, environment, cmp_id, role, content, citations, grounded,
              provider, model, latency_ms)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)
             RETURNING id'
        );
        $st->execute([
            $conversationId,
            $ctx->environment,
            $ctx->cmpId,
            $role,
            mb_substr($content, 0, 60000),
            json_encode($citations, JSON_UNESCAPED_SLASHES),
            $grounded ? 'true' : 'false',
            $reply === null ? null : mb_substr((string) $reply['provider'], 0, 32),
            $reply === null ? null : mb_substr((string) $reply['model'], 0, 96),
            $reply === null ? null : (int) $reply['latency_ms'],
        ]);

        return (int) $st->fetchColumn();
    }
}
