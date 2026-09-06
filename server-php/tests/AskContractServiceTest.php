<?php

declare(strict_types=1);

/**
 * Grounded question answering.
 *
 * The assertions that matter here are about what the model is *not* given. A
 * question about one contract must be answered from that contract's text and
 * nothing else — not another contract in the same company, and certainly not
 * another company's — and the check is made against the prompt the provider
 * actually received rather than against the answer, because an answer can be
 * right by luck and a prompt cannot.
 *
 * The rest is honesty: an answer the contract does not support is stored as
 * ungrounded and says so in plain words, rather than being presented in the
 * same voice as one that quotes clause 7.2.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Ai\ContractsAiProvider;
use App\Services\AskContractService;
use App\Services\ContractService;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

/** A provider that answers from a script and remembers every prompt it was shown. */
final class RecordingAiProvider implements ContractsAiProvider
{
    /** @var list<array{messages: list<array{role: string, content: string}>, options: array<string,mixed>}> */
    public array $calls = [];

    /** @param list<string> $replies */
    public function __construct(public array $replies)
    {
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function complete(array $messages, array $options = []): array
    {
        $this->calls[] = ['messages' => $messages, 'options' => $options];

        return [
            'text'          => count($this->replies) > 1 ? array_shift($this->replies) : ($this->replies[0] ?? '{}'),
            'prompt_tokens' => 900,
            'output_tokens' => 120,
            'model'         => 'scripted-1',
            'raw'           => [],
        ];
    }

    public function lastPrompt(): string
    {
        $out = '';
        foreach ($this->calls[count($this->calls) - 1]['messages'] ?? [] as $message) {
            $out .= $message['content'] . "\n";
        }

        return $out;
    }
}

// ---------------------------------------------------------------------------
// Fixtures: two contracts in one company, one in another
// ---------------------------------------------------------------------------

$ctx      = t_context();
$otherCtx = t_context(2, 'USER-B');

$makeContract = static function (string $number, string $title, string $text, int $cmpId = 1) use ($pdo): int {
    $st = $pdo->prepare(
        'INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage, created_by)
         VALUES (?, ?, ?, ?, \'active\', \'active\', \'USER-A\') RETURNING id'
    );
    $st->execute(['sandbox', $cmpId, $number, $title]);
    $contractId = (int) $st->fetchColumn();

    $doc = $pdo->prepare(
        'INSERT INTO contract_documents (environment, cmp_id, contract_id, doc_kind, title, created_by)
         VALUES (?, ?, ?, \'contract\', ?, \'USER-A\') RETURNING id'
    );
    $doc->execute(['sandbox', $cmpId, $contractId, $title]);

    $version = $pdo->prepare(
        'INSERT INTO contract_document_versions
         (document_id, environment, cmp_id, version_no, filename, content_type, storage_provider,
          drive_document_id, extracted_text, extracted_pages)
         VALUES (?, ?, ?, 1, \'doc.pdf\', \'application/pdf\', \'drive\', \'DRIVE-1\', ?, 2)'
    );
    $version->execute([(int) $doc->fetchColumn(), 'sandbox', $cmpId, $text]);

    return $contractId;
};

$supplyText = "SUPPLY AGREEMENT\n\n4.1 The supplier shall deliver within thirty days of order.\n\n"
    . "8.2 Liability is capped at the fees paid in the preceding twelve months.\n";

// The distinctive strings below are what the scope assertions look for. If any
// of them reaches a prompt about a different contract, retrieval has leaked.
$leaseText = "LEASE DEED\n\n3.4 The tenant shall pay a security deposit of PURPLE-MONKEY-DEPOSIT rupees.\n";

$rivalText = "SETTLEMENT AGREEMENT\n\n2.1 The rival company pays ORANGE-ELEPHANT-SETTLEMENT in full.\n";

$supplyId = $makeContract('CON-2026-000001', 'Supply Agreement', $supplyText);
$leaseId  = $makeContract('CON-2026-000002', 'Lease Deed', $leaseText);
$rivalId  = $makeContract('CON-OTHER-000001', 'Settlement', $rivalText, 2);

$groundedReply = json_encode([
    'answered'   => true,
    'answer'     => 'Liability is capped at the fees paid in the preceding twelve months.',
    'confidence' => 0.9,
    'citations'  => [
        ['clause_reference' => '8.2', 'heading' => 'Liability', 'page' => 1,
         'excerpt' => 'Liability is capped at the fees paid in the preceding twelve months.'],
    ],
]);

$refusalReply = json_encode([
    'answered'   => false,
    'answer'     => 'This is not stated in this contract.',
    'confidence' => 0.8,
    'citations'  => [],
]);

// ---------------------------------------------------------------------------
// A question the contract answers
// ---------------------------------------------------------------------------

$provider = new RecordingAiProvider([$groundedReply]);
$service  = new AskContractService($pdo, $provider);

$answer = $service->ask($ctx, $supplyId, 'What is the liability cap?');

assert_true($answer['grounded'], 'an answer the contract supports is grounded');
assert_count(1, $answer['citations'], 'and carries the wording it was read from');
assert_same('8.2', $answer['citations'][0]['clause_reference'], 'cited by the document\'s own numbering');
assert_contains('not legal advice', $answer['disclaimer'], 'and is shown with the boundary of what it is');

$stored = $pdo->prepare('SELECT * FROM ai_messages WHERE conversation_id = ? ORDER BY id');
$stored->execute([$answer['conversation_id']]);
$messages = $stored->fetchAll();
assert_same(2, count($messages), 'the question and the answer are both kept');
assert_same('user', $messages[0]['role'], 'the question first');
assert_true(ContractService::toBool($messages[1]['grounded']), 'and the answer marked grounded');
assert_same('scripted', $messages[1]['provider'], 'with the provider that produced it');

// ---------------------------------------------------------------------------
// The prompt carries this contract and no other
// ---------------------------------------------------------------------------

$prompt = $provider->lastPrompt();
assert_contains('Liability is capped', $prompt, 'the contract asked about is in the prompt');
assert_not_contains('PURPLE-MONKEY-DEPOSIT', $prompt, 'another contract in the same company is not');
assert_not_contains('ORANGE-ELEPHANT-SETTLEMENT', $prompt, 'and another company\'s contract certainly is not');
assert_contains('BEGIN_UNTRUSTED_DOCUMENT', $prompt, 'the document is fenced as data rather than instruction');
assert_contains('not stated in this contract', $prompt, 'and the model is told it may say the contract is silent');

// Asking the same question against the other contract must reach the other
// text. This is the half that a hard-coded contract id would still pass.
$leaseProvider = new RecordingAiProvider([$refusalReply]);
$leaseService  = new AskContractService($pdo, $leaseProvider);
$leaseAnswer   = $leaseService->ask($ctx, $leaseId, 'What is the liability cap?');

assert_contains('PURPLE-MONKEY-DEPOSIT', $leaseProvider->lastPrompt(), 'a question about the lease is answered from the lease');
assert_not_contains('Liability is capped', $leaseProvider->lastPrompt(), 'and not from the supply agreement');

// ---------------------------------------------------------------------------
// A question the contract does not answer
// ---------------------------------------------------------------------------

assert_false($leaseAnswer['grounded'], 'a question the text does not answer is not grounded');
assert_contains('not stated in this contract', $leaseAnswer['answer'], 'and says so plainly');
assert_count(0, $leaseAnswer['citations'], 'with nothing cited for it');

$leaseStored = $pdo->prepare('SELECT grounded FROM ai_messages WHERE conversation_id = ? AND role = \'assistant\'');
$leaseStored->execute([$leaseAnswer['conversation_id']]);
assert_false(ContractService::toBool($leaseStored->fetchColumn()), 'and is stored as ungrounded, so a screen can show it as such');

// An answer with no citation is not allowed to present itself as grounded,
// whatever the model claims about itself.
$boastful = new RecordingAiProvider([json_encode([
    'answered'   => true,
    'answer'     => 'The deposit is three months rent.',
    'confidence' => 0.99,
    'citations'  => [],
])]);
$boastAnswer = (new AskContractService($pdo, $boastful))->ask($ctx, $leaseId, 'How much is the deposit?');
assert_false($boastAnswer['grounded'], 'a confident answer with nothing to check is treated as ungrounded');

// ---------------------------------------------------------------------------
// Conversations stay attached to their contract
// ---------------------------------------------------------------------------

$followUp = new RecordingAiProvider([$groundedReply]);
$service2 = new AskContractService($pdo, $followUp);
$second   = $service2->ask($ctx, $supplyId, 'Does that cap cover indirect losses?', $answer['conversation_id']);

assert_same($answer['conversation_id'], $second['conversation_id'], 'a follow-up continues the same conversation');
assert_contains('What is the liability cap?', $followUp->lastPrompt(), 'and the earlier turn is carried into the prompt');

assert_throws(
    static fn () => $service2->ask($ctx, $leaseId, 'And here?', $answer['conversation_id']),
    'a conversation about one contract cannot be continued against another',
    'Conversation not found'
);

assert_throws(
    static fn () => $service2->ask($otherCtx, $supplyId, 'What is the liability cap?'),
    'another company cannot ask about this contract at all',
    'Contract not found'
);

assert_throws(
    static fn () => $service2->ask($otherCtx, $rivalId, 'What is the settlement?', $answer['conversation_id']),
    'nor borrow this company\'s conversation',
    'Conversation not found'
);

assert_throws(
    static fn () => $service2->ask($ctx, $supplyId, '   '),
    'an empty question is refused before anything is paid for',
    'Ask a question'
);

// ---------------------------------------------------------------------------
// Listing
// ---------------------------------------------------------------------------

$conversations = $service->conversations($ctx, $supplyId);
assert_same(1, $conversations['total'], 'the supply agreement has one conversation');
assert_same(4, $conversations['items'][0]['message_count'], 'holding both exchanges');
assert_same('CON-2026-000001', $conversations['items'][0]['contract_number'], 'shown against its contract');

assert_same(0, $service->conversations($otherCtx, $supplyId)['total'], 'another company sees none of them');

$thread = $service->messages($ctx, (int) $answer['conversation_id']);
assert_same(4, count($thread), 'the thread reads back in order');
assert_same('user', $thread[0]['role'], 'question first');
assert_same('assistant', $thread[1]['role'], 'then the answer');

assert_throws(
    static fn () => $service->messages($otherCtx, (int) $answer['conversation_id']),
    'another company cannot read the thread',
    'Conversation not found'
);

// ---------------------------------------------------------------------------
// Nothing to read
// ---------------------------------------------------------------------------

$emptyContract = $pdo->prepare(
    'INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage, created_by)
     VALUES (\'sandbox\', 1, \'CON-2026-000009\', \'No document yet\', \'draft\', \'draft\', \'USER-A\') RETURNING id'
);
$emptyContract->execute();
$emptyId = (int) $emptyContract->fetchColumn();

$callsBefore = count($provider->calls);
assert_throws(
    static fn () => $service->ask($ctx, $emptyId, 'What does this say?'),
    'a contract with no readable text refuses rather than answering "not stated"',
    'no readable document text'
);
assert_same($callsBefore, count($provider->calls), 'and costs nothing, because no call was made');

t_done('AskContractServiceTest');
