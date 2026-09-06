<?php

declare(strict_types=1);

namespace App\Ai\Prompts;

use App\Ai\PromptGuard;
use App\Ai\Schemas\ExtractionSchema;
use App\Support\Enums;

/**
 * Every prompt this product sends to a model, in one file.
 *
 * A prompt is not a string literal near the code that happens to need it. It is
 * the specification of what the model is asked to do, it decides what lands in
 * a contract record, and a one-word change to it changes the product's output
 * across every tenant. Keeping them together means a change to any of them
 * shows up as a diff in this file and can be reviewed as what it is.
 *
 * Four rules hold across all of them, and they are enforced by the shared
 * blocks below rather than restated per method, so none can be left out of a
 * new prompt by accident:
 *
 *   1. Document text goes through PromptGuard::wrapUntrusted. What we analyse
 *      arrived from a counterparty; it is evidence, never instruction.
 *   2. The reply is JSON matching a schema in ExtractionSchema, with no prose
 *      around it. The caller validates it and refuses to store anything that
 *      does not match.
 *   3. Every extracted value carries a confidence and the excerpt it was read
 *      from, so a reviewer can check the value against the sentence rather
 *      than trusting it.
 *   4. A term the document does not state comes back null. An invented value
 *      is worse than a missing one: somebody acts on it, and nothing
 *      downstream can tell it apart from a term that was really there.
 *
 * Nothing here asks the model for a legal conclusion. Risk is phrased as
 * elevated or reduced exposure and always tied to the wording it comes from —
 * this product reports what a contract says, it does not advise on it.
 *
 * @phpstan-type Message array{role: string, content: string}
 */
final class ContractPrompts
{
    /** How much of one clause or excerpt is worth quoting back. Longer is paid for twice: once in tokens, once in a review screen nobody reads. */
    public const MAX_EXCERPT_CHARS = 300;

    /**
     * The field-level contract every extraction prompt shares.
     *
     * Written out in full rather than referred to, because a model follows a
     * shape it has been shown far more reliably than one it has been named.
     */
    private const FIELD_CONTRACT = <<<'TXT'
    Reply with one JSON document and nothing else: no preamble, no explanation, no
    markdown fence, no text after the closing brace.

    Every extracted field is an object of exactly this shape:

      {"value": <the term as the document states it, or null>,
       "confidence": <a number from 0 to 1>,
       "source_page": <the page the value was read from, or null>,
       "source_excerpt": <up to 300 characters quoted from the document, or null>}

    - value is null whenever the document does not state the term. Do not supply a
      market-standard figure, a usual duration, a likely governing law or anything
      else the text does not say. "Not stated" is a correct and useful answer; a
      plausible guess is a defect, because somebody will act on it.
    - confidence is how clearly the document states the term: near 1 when it is
      written in so many words, lower when you had to read it out of indirect
      wording. A null value still carries a confidence, which then describes how
      sure you are that the document is silent.
    - source_excerpt is quoted verbatim from the document. If you cannot quote a
      passage that supports the value, the value is not in the document and must
      be null.
    - source_page is the page number the excerpt sits on, or null when the text
      you were given carries no page markers.
    TXT;

    /** The boundary of what this product is allowed to say. Restated per call because it is the one instruction a long document is most likely to push out of view. */
    private const RISK_LANGUAGE = <<<'TXT'
    Describe exposure, never legal status. Say that a term leaves the company with
    elevated or reduced exposure and name the wording it comes from. Do not say a
    clause is invalid, unenforceable, void or illegal, do not say what the company
    should do, and do not state a legal conclusion. A qualified reviewer decides
    those; your job is to show them what the contract says.
    TXT;

    /**
     * Stage 3 of the pipeline: what kind of agreement is this.
     *
     * The known type list is offered rather than imposed — a company's own type
     * names are what the rest of the product files a contract under, but a
     * document that is none of them must be allowed to say so instead of being
     * forced into the nearest label.
     *
     * @param  list<string>   $knownTypes the tenant's configured contract type names
     * @return list<Message>
     */
    public static function classifyContract(string $documentText, array $knownTypes = []): array
    {
        $known = self::bulletList($knownTypes, 40);
        $catalogue = $known === ''
            ? "This company has no contract types configured, so answer with a descriptive\nname of your own.\n"
            : "This company files contracts under these types:\n{$known}\n"
                . "Use one of them when the document plainly is one. When it is not, set\n"
                . "matched_known_type to null and give your own descriptive name in document_type.\n";

        return self::messages(
            'identify what kind of agreement a document is',
            "Identify the kind of agreement this document is.\n\n"
            . $catalogue . "\n"
            . self::FIELD_CONTRACT . "\n\n"
            . "Also report whether the document reads as a complete executed agreement, a\n"
            . "draft, an annexure or a fragment, and say which in document_completeness.\n",
            $documentText,
            'contract document'
        );
    }

    /**
     * Stage 4: the structured record — parties, dates, money, renewal, notice,
     * governing law.
     *
     * These are the fields that drive renewal sweeps and expiry alerts, which is
     * why the instruction spends its length on the difference between dates that
     * a careless reader collapses into one.
     *
     * @return list<Message>
     */
    public static function extractContractData(string $documentText, string $label = 'contract document'): array
    {
        $renewalTypes = implode(', ', Enums::RENEWAL_TYPES);
        $frequencies  = implode(', ', Enums::RENEWAL_FREQUENCIES);

        return self::messages(
            'extract the structured terms of a contract',
            "Extract the structured terms of this agreement.\n\n"
            . self::FIELD_CONTRACT . "\n\n"
            . "Field notes:\n"
            . "- effective_date is when the terms begin. execution_date is when it was\n"
            . "  signed. commencement_date is when performance starts. They are frequently\n"
            . "  different dates in one agreement; report each only from wording that names\n"
            . "  that specific one, and leave the others null.\n"
            . "- expiry_date is the end of the current term. If the document states a term\n"
            . "  length instead of an end date, leave expiry_date null and put the wording\n"
            . "  in term_description; do not compute a date the contract does not state.\n"
            . "- All dates are YYYY-MM-DD. A date written '31 March 2027' is 2027-03-31.\n"
            . "  Where a date is ambiguous between conventions, leave it null and quote the\n"
            . "  wording in source_excerpt.\n"
            . "- total_value is the whole consideration for the term; recurring_value is one\n"
            . "  periodic charge. Report the number only, with no currency symbol or\n"
            . "  thousands separator, and put the ISO code in currency.\n"
            . "- renewal_type is one of: {$renewalTypes}. renewal_frequency is one of:\n"
            . "  {$frequencies}. Use null rather than the nearest fit.\n"
            . "- notice_period_days is the notice a party must give to prevent renewal or to\n"
            . "  terminate, in days. Convert plain multiples ('three months' is 90) and leave\n"
            . "  anything conditional null with the wording in source_excerpt.\n"
            . "- governing_law is the law of the contract; jurisdiction is where disputes are\n"
            . "  heard. They differ often enough to be worth reading separately.\n\n"
            . "parties is a list of every party the document names, each with the role it\n"
            . "plays. Name them exactly as written on the signature block or the preamble,\n"
            . "not as you would abbreviate them.\n",
            $documentText,
            $label
        );
    }

    /**
     * Stage 5, clauses. Asked for as the document's own structure — clause
     * number, heading, text — because a finding shown against clause 14.2 can
     * be checked, and one asserted in the abstract cannot.
     *
     * @param  list<string>   $categories clause category names configured by the tenant
     * @return list<Message>
     */
    public static function extractClauses(string $documentText, array $categories = []): array
    {
        $list = self::bulletList($categories, 60);
        $categoryNote = $list === ''
            ? "Give each clause a short category name of your own.\n"
            : "Categorise each clause using one of these names where it fits, and null where\nnone of them does:\n{$list}\n";

        return self::messages(
            'list the clauses of a contract as the document sets them out',
            "List the substantive clauses of this document as it sets them out.\n\n"
            . $categoryNote . "\n"
            . "For each clause return:\n"
            . "  clause_number     the document's own numbering, or null if it has none\n"
            . "  heading           the clause heading, or null\n"
            . "  category          as described above, or null\n"
            . "  body_text         the clause text, quoted from the document, up to 4000\n"
            . "                    characters. Never paraphrase it: this text is shown to a\n"
            . "                    reviewer as what the contract says.\n"
            . "  summary           one sentence on what it does, in your own words\n"
            . "  confidence        0 to 1, how sure you are this is one distinct clause\n"
            . "  source_page       the page it starts on, or null\n"
            . "  source_excerpt    the first sentence, quoted, up to 300 characters\n\n"
            . "Skip recitals, signature blocks, page furniture and tables of contents. Do not\n"
            . "invent a clause the document does not contain, and do not merge two clauses\n"
            . "into one because they are related.\n\n"
            . self::RISK_LANGUAGE . "\n\n"
            . self::jsonOnly(),
            $documentText,
            'contract document'
        );
    }

    /**
     * Stage 5, obligations: what the agreement requires somebody to actually do.
     *
     * The responsible-party and frequency vocabularies are the ones the
     * obligations table stores, so an answer maps onto a row without a
     * translation step that could quietly pick a default.
     *
     * @return list<Message>
     */
    public static function extractObligations(string $documentText): array
    {
        $responsible = implode(', ', Enums::OBLIGATION_RESPONSIBLE);
        $frequencies = implode(', ', Enums::OBLIGATION_FREQUENCIES);

        return self::messages(
            'list the obligations a contract places on each party',
            "List the obligations this agreement places on the parties: the things somebody\n"
            . "has to do, deliver, pay, report or maintain.\n\n"
            . "For each obligation return:\n"
            . "  title             a short imperative description, at most 200 characters\n"
            . "  description       what is required, in one or two sentences\n"
            . "  responsible_party one of: {$responsible}. 'company' is the party that\n"
            . "                    uploaded this document for review; when the document does\n"
            . "                    not make the sides clear, use null.\n"
            . "  frequency         one of: {$frequencies}, or null\n"
            . "  first_due_date    YYYY-MM-DD, only when the document states a date. A date\n"
            . "                    you derived from a term length is not a stated date.\n"
            . "  amount            the sum involved as a plain number, or null\n"
            . "  currency          ISO code for that amount, or null\n"
            . "  evidence_required true only where the document requires proof, a report or a\n"
            . "                    certificate to be produced\n"
            . "  clause_reference  the clause number it comes from, or null\n"
            . "  confidence        0 to 1\n"
            . "  source_page       or null\n"
            . "  source_excerpt    quoted from the document, up to 300 characters\n\n"
            . "An obligation must come from wording that requires something. A statement of\n"
            . "intent, a recital or a definition is not an obligation; leave it out rather\n"
            . "than list it with a low confidence.\n\n"
            . self::jsonOnly(),
            $documentText,
            'contract document'
        );
    }

    /**
     * Stage 5, milestones: the dated events in the agreement.
     *
     * A milestone with no date is not a milestone — the schedule is the whole
     * point of the record — so the prompt says to leave it out rather than
     * offer a guessed date the storage layer would then have to reject.
     *
     * @return list<Message>
     */
    public static function extractMilestones(string $documentText): array
    {
        return self::messages(
            'list the dated milestones in a contract',
            "List the dated milestones this agreement sets: deliveries, go-live dates, phase\n"
            . "completions, review points, staged payments, expiry of a standstill.\n\n"
            . "For each milestone return:\n"
            . "  title           a short description, at most 200 characters\n"
            . "  description     what happens at this point, in one or two sentences\n"
            . "  milestone_type  a short label such as delivery, payment, review, renewal\n"
            . "  due_date        YYYY-MM-DD, taken from the document\n"
            . "  amount          a plain number where money attaches to it, or null\n"
            . "  currency        ISO code for that amount, or null\n"
            . "  clause_reference the clause it comes from, or null\n"
            . "  confidence      0 to 1\n"
            . "  source_page     or null\n"
            . "  source_excerpt  quoted from the document, up to 300 characters\n\n"
            . "Only include a milestone whose date the document actually states. If the text\n"
            . "says 'within 30 days of signature' and gives no signature date, leave the\n"
            . "milestone out entirely — a date computed from an unknown starting point would\n"
            . "be presented to somebody as a deadline.\n\n"
            . self::jsonOnly(),
            $documentText,
            'contract document'
        );
    }

    /**
     * The management summary: one section per thing a reviewer needs to know.
     *
     * The section list comes from ExtractionSchema so the prompt and the schema
     * the answer is checked against cannot drift apart.
     *
     * @param  array<string,mixed> $facts structured terms already extracted and verified
     * @return list<Message>
     */
    public static function summarizeContract(string $documentText, array $facts = []): array
    {
        $sections = '';
        foreach (ExtractionSchema::SUMMARY_SECTIONS as $key => $label) {
            $sections .= sprintf("  %-22s %s\n", $key, $label);
        }

        $known = $facts === []
            ? ''
            : "The structured record already holds these terms. Where the document agrees,\n"
                . "use them; where it does not, describe what the document says and note the\n"
                . "difference. They are given as context, not as something to repeat back:\n"
                . self::compactFacts($facts) . "\n\n";

        return self::messages(
            'summarise a contract for the people who have to manage it',
            "Summarise this agreement for the people who have to manage it: an owner, a\n"
            . "finance reviewer and a lawyer reading it for the first time.\n\n"
            . $known
            . "Return one section per key below. Each is an object with content (prose, at\n"
            . "most 1200 characters), confidence (0 to 1), source_page and source_excerpt.\n"
            . "A section the document says nothing about has content null and a confidence\n"
            . "describing how sure you are of that silence — an empty Data Protection\n"
            . "section is itself a finding, and inventing one hides it.\n\n"
            . $sections . "\n"
            . "management_action_items is different: it is a list, each entry with action\n"
            . "(what a person would put on a to-do list), why_it_matters, urgency (one of\n"
            . "immediate, before_signature, before_renewal, routine), confidence,\n"
            . "source_page and source_excerpt. An empty list is a valid answer.\n\n"
            . "missing_protections is where you name protections a contract of this kind\n"
            . "commonly carries and this one does not. Say what is absent and why its\n"
            . "absence leaves the company more exposed; do not say the contract is defective.\n\n"
            . self::RISK_LANGUAGE . "\n\n"
            . self::jsonOnly(),
            $documentText,
            'contract document'
        );
    }

    /**
     * The narrative half of risk. The deterministic half is RiskEngine, whose
     * findings are handed over here so the model explains the rules that fired
     * rather than inventing a parallel opinion.
     *
     * @param  list<array<string,mixed>> $ruleFindings findings from RiskEngine
     * @return list<Message>
     */
    public static function assessRiskNarrative(string $documentText, array $ruleFindings = []): array
    {
        $existing = $ruleFindings === []
            ? "The rules engine reported nothing on this contract.\n"
            : "The rules engine already reported these. Explain them against the wording and\n"
                . "do not repeat them as new findings:\n" . self::compactFindings($ruleFindings) . "\n";

        return self::messages(
            'explain where a contract leaves the company more or less exposed',
            "Read this agreement and report where its wording leaves the company more or\n"
            . "less exposed than a plainly balanced version of the same agreement would.\n\n"
            . $existing . "\n"
            . "For each finding return:\n"
            . "  category       one of: " . implode(', ', Enums::RISK_CATEGORIES) . "\n"
            . "  severity       one of: " . implode(', ', Enums::RISK_SEVERITIES) . "\n"
            . "  title          a short statement of what the wording does\n"
            . "  detail         what the clause says and what exposure follows from it\n"
            . "  clause_reference the clause number, or null\n"
            . "  confidence     0 to 1\n"
            . "  source_page    or null\n"
            . "  source_excerpt quoted from the document, up to 300 characters\n\n"
            . "A finding with no quotable wording behind it is not a finding. Leave it out.\n\n"
            . self::RISK_LANGUAGE . "\n\n"
            . self::jsonOnly(),
            $documentText,
            'contract document'
        );
    }

    /**
     * What changed between two drafts, and what the change does.
     *
     * Both versions are fenced separately and labelled: the model has to be
     * able to say which side a passage came from, and a single merged block
     * makes that impossible to check afterwards.
     *
     * @return list<Message>
     */
    public static function compareVersions(string $baseText, string $targetText, string $baseLabel = 'earlier version', string $targetLabel = 'later version'): array
    {
        $instructions = "Two versions of the same agreement follow. Report what changed from the\n"
            . "earlier one to the later one and what each change does to the company's\n"
            . "position.\n\n"
            . "For each change return:\n"
            . "  clause_reference  the clause number, or null\n"
            . "  change_type       one of: added, removed, amended, moved\n"
            . "  before_excerpt    the earlier wording, quoted, or null when it is new\n"
            . "  after_excerpt     the later wording, quoted, or null when it was removed\n"
            . "  effect            what the change does, in one or two sentences\n"
            . "  direction         one of: favours_company, favours_counterparty, neutral,\n"
            . "                    unclear\n"
            . "  materiality       one of: informational, low, medium, high, critical\n"
            . "  confidence        0 to 1\n\n"
            . "Report substantive changes only. Renumbering, reformatting and typographical\n"
            . "corrections are noise here; if that is all that changed, return an empty list\n"
            . "and say so in overall_assessment.\n\n"
            . self::RISK_LANGUAGE . "\n\n"
            . self::jsonOnly();

        return [
            self::system('compare two versions of a contract and report what changed'),
            [
                'role'    => 'user',
                'content' => $instructions . "\n\n"
                    . PromptGuard::wrapUntrusted($baseText, $baseLabel) . "\n\n"
                    . PromptGuard::wrapUntrusted($targetText, $targetLabel),
            ],
        ];
    }

    /**
     * Grounded question answering over one contract.
     *
     * The two hard requirements are here rather than in the service: the model
     * must be allowed — required — to answer "not stated in this contract", and
     * anything it does assert must carry the clause it came from. A confident
     * answer with no citation is the failure mode this whole feature has to
     * avoid, because it reads exactly like a correct one.
     *
     * @param  list<array{role: string, content: string}> $history earlier turns in this conversation
     * @param  list<array<string,mixed>>                  $clauses extracted clauses for citation by number
     * @return list<Message>
     */
    public static function answerQuestion(string $question, string $documentText, array $clauses = [], array $history = []): array
    {
        $clauseIndex = self::clauseIndex($clauses);

        $instructions = "Answer the question below using only the contract text provided.\n\n"
            . "Rules for this answer, in order:\n"
            . "1. If the contract text does not contain the answer, set answered to false and\n"
            . "   write exactly: \"This is not stated in this contract.\" — optionally followed\n"
            . "   by one sentence on what the contract does say about the surrounding subject.\n"
            . "   Do not answer from general knowledge of what such contracts usually say.\n"
            . "   A user asking this question is deciding something; \"not stated\" tells them\n"
            . "   to go and look, and a plausible invention does not.\n"
            . "2. Every assertion you do make carries a citation: the clause number where\n"
            . "   available, and always a quoted excerpt of up to 300 characters that a reader\n"
            . "   can find in the document. An answer with no citations must have answered\n"
            . "   set to false.\n"
            . "3. Answer about this contract only. You have been given one contract; if the\n"
            . "   question asks about another agreement, a portfolio or an outside fact, that\n"
            . "   is a question this text cannot answer.\n"
            . "4. " . str_replace("\n", "\n   ", self::RISK_LANGUAGE) . "\n\n"
            . $clauseIndex
            . self::jsonOnly();

        $messages = [self::system('answer a question about one contract, from that contract only')];

        foreach ($history as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = PromptGuard::sanitise((string) ($turn['content'] ?? ''), 4000);
            if ($text !== '') {
                $messages[] = ['role' => $role, 'content' => $text];
            }
        }

        $messages[] = [
            'role'    => 'user',
            'content' => $instructions . "\n\n"
                . PromptGuard::wrapUntrusted($documentText, 'contract document') . "\n\n"
                . "Question: " . PromptGuard::sanitise($question, 2000) . "\n",
        ];

        return $messages;
    }

    /**
     * Renewal advice, which is the one prompt where the product is asked what
     * to do — so it is bounded hard: options the contract itself creates,
     * dates the contract itself states, and the consequence of doing nothing.
     *
     * @param  array<string,mixed> $contract the structured record
     * @return list<Message>
     */
    public static function renewalRecommendation(array $contract, string $documentText = ''): array
    {
        $instructions = "This contract is approaching its renewal decision. Set out the options the\n"
            . "agreement itself creates and what follows from each.\n\n"
            . "The structured record:\n" . self::compactFacts($contract) . "\n\n"
            . "Return:\n"
            . "  recommendation      one of: renew, renegotiate, terminate, review_further\n"
            . "  rationale           why, in three sentences or fewer, from the contract's own\n"
                . "                      terms and dates\n"
            . "  notice_position     what the notice clause requires and by when, or null when\n"
                . "                      the record and the text do not state it\n"
            . "  if_nothing_is_done  what happens on the current terms if nobody acts. For an\n"
                . "                      auto-renewing contract this is the sentence that matters\n"
                . "                      most on the page.\n"
            . "  points_to_negotiate a list of terms worth revisiting, each with a reason\n"
            . "  risks_of_renewing   a list, each an exposure that continues if renewed as is\n"
            . "  confidence          0 to 1\n"
            . "  source_excerpt      quoted from the contract where the text was given, or null\n\n"
            . "Base every statement on the record and the text above. Where the notice window\n"
            . "has already closed, say so plainly rather than recommending an action that is\n"
            . "no longer available.\n\n"
            . self::RISK_LANGUAGE . "\n\n"
            . self::jsonOnly();

        $messages = [self::system('set out the renewal options a contract creates')];

        $messages[] = [
            'role'    => 'user',
            'content' => $documentText === ''
                ? $instructions
                : $instructions . "\n\n" . PromptGuard::wrapUntrusted($documentText, 'contract document'),
        ];

        return $messages;
    }

    /**
     * How one clause departs from the company's own preferred position.
     *
     * The playbook wording is the company's own text and is still fenced: it
     * reaches this call out of a database row that an admin typed, and the
     * cheapest way to keep that from becoming an instruction channel is to
     * treat it exactly like the counterparty's draft.
     *
     * @return list<Message>
     */
    public static function clauseDeviation(string $contractWording, string $preferredWording, string $ruleDescription = ''): array
    {
        $rule = trim($ruleDescription) === ''
            ? ''
            : "The company's rule for this clause type:\n"
                . PromptGuard::wrapUntrusted($ruleDescription, 'company playbook rule') . "\n\n";

        $instructions = "Compare the clause as drafted against the company's preferred wording and\n"
            . "report how the drafted version differs.\n\n"
            . "Return:\n"
            . "  deviates          true only where the drafted clause gives a materially\n"
                . "                    different outcome. Different words with the same effect is\n"
                . "                    not a deviation.\n"
            . "  deviation_summary what differs, in two sentences or fewer\n"
            . "  severity          one of: " . implode(', ', Enums::RISK_SEVERITIES) . "\n"
            . "  affected_position what the company gives up or gains compared with its\n"
                . "                    preferred wording\n"
            . "  fallback_position what a middle position between the two would look like, or\n"
                . "                    null where there is no sensible middle\n"
            . "  confidence        0 to 1\n"
            . "  source_excerpt    the part of the drafted clause that carries the difference,\n"
                . "                    quoted, up to 300 characters\n\n"
            . self::RISK_LANGUAGE . "\n\n"
            . self::jsonOnly();

        return [
            self::system('compare a drafted clause against the company preferred wording'),
            [
                'role'    => 'user',
                'content' => $instructions . "\n\n" . $rule
                    . PromptGuard::wrapUntrusted($contractWording, 'clause as drafted') . "\n\n"
                    . PromptGuard::wrapUntrusted($preferredWording, 'company preferred wording'),
            ],
        ];
    }

    /**
     * The second and last attempt at a reply that did not match its schema.
     *
     * The validator's own error strings are handed to the model verbatim. They
     * name the path and what was expected, which is more use to it than a
     * restatement of the schema it has already seen once — and a call that
     * fails this too is a genuine failure worth reporting rather than looping on.
     *
     * @param  list<Message> $messages the original conversation
     * @param  list<string>  $errors   messages from JsonSchemaValidator
     * @return list<Message>
     */
    public static function stricterRetry(array $messages, array $errors): array
    {
        $list = '';
        foreach (array_slice($errors, 0, 12) as $error) {
            $list .= '  - ' . PromptGuard::sanitise((string) $error, 300) . "\n";
        }

        $messages[] = [
            'role'    => 'user',
            'content' => "That reply did not match the required format and was discarded. The problems:\n"
                . $list . "\n"
                . "Send the whole answer again as one JSON document. Start your reply with { and\n"
                . "end it with }. No markdown fence, no sentence before or after it. Include every\n"
                . "required property, use null for anything the document does not state, and keep\n"
                . "dates as YYYY-MM-DD. Do not change any value you were confident about — this is\n"
                . "the same answer in the correct shape, not a second opinion.",
        ];

        return $messages;
    }

    // -----------------------------------------------------------------------
    // Shared construction
    // -----------------------------------------------------------------------

    /**
     * System turn plus one user turn holding the instructions and the fenced
     * document. Every simple prompt is this shape; the ones that are not build
     * their message list directly.
     *
     * @return list<Message>
     */
    private static function messages(string $task, string $instructions, string $documentText, string $label): array
    {
        return [
            self::system($task),
            [
                'role'    => 'user',
                'content' => $instructions . "\n\n" . PromptGuard::wrapUntrusted($documentText, $label),
            ],
        ];
    }

    /** @return Message */
    private static function system(string $task): array
    {
        return ['role' => 'system', 'content' => PromptGuard::systemPreamble($task)];
    }

    private static function jsonOnly(): string
    {
        return "Reply with one JSON document and nothing else: no preamble, no explanation, no\n"
            . "markdown fence, no text after the closing brace. Use null for anything the\n"
            . "document does not state, and never invent a value to fill a required field.";
    }

    /**
     * Tenant-supplied names — contract types, clause categories — reach the
     * prompt as a bullet list. They come from a database row somebody typed,
     * so they are sanitised on the way in like any other untrusted text.
     *
     * @param list<string> $values
     */
    private static function bulletList(array $values, int $max): string
    {
        $out = '';
        foreach (array_slice(array_values($values), 0, $max) as $value) {
            $clean = trim(PromptGuard::sanitise((string) $value, 120));
            if ($clean !== '') {
                $out .= '  - ' . $clean . "\n";
            }
        }

        return $out;
    }

    /**
     * Structured record values as `key: value` lines.
     *
     * Deliberately not JSON: a JSON object in the prompt invites the model to
     * echo it back as its answer, and these are context rather than the shape
     * of the reply.
     *
     * @param array<string,mixed> $facts
     */
    private static function compactFacts(array $facts): string
    {
        $out = '';
        foreach ($facts as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $text = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
            $out .= '  ' . PromptGuard::sanitise((string) $key, 60) . ': '
                . trim(PromptGuard::sanitise($text, 300)) . "\n";
        }

        return $out === '' ? "  (the structured record is empty)\n" : $out;
    }

    /** @param list<array<string,mixed>> $findings */
    private static function compactFindings(array $findings): string
    {
        $out = '';
        foreach (array_slice($findings, 0, 40) as $finding) {
            $title = trim(PromptGuard::sanitise((string) ($finding['title'] ?? ''), 200));
            if ($title === '') {
                continue;
            }
            $severity = trim(PromptGuard::sanitise((string) ($finding['severity'] ?? 'medium'), 20));
            $out .= '  - [' . $severity . '] ' . $title . "\n";
        }

        return $out === '' ? "  (none)\n" : $out;
    }

    /**
     * The clause numbers already extracted for this contract, so an answer can
     * cite one by the document's own numbering instead of a paraphrase.
     *
     * @param list<array<string,mixed>> $clauses
     */
    private static function clauseIndex(array $clauses): string
    {
        $lines = '';
        foreach (array_slice($clauses, 0, 120) as $clause) {
            $number  = trim(PromptGuard::sanitise((string) ($clause['clause_number'] ?? ''), 48));
            $heading = trim(PromptGuard::sanitise((string) ($clause['heading'] ?? ''), 160));
            if ($number === '' && $heading === '') {
                continue;
            }
            $lines .= '  ' . ($number === '' ? '-' : $number) . ' ' . $heading . "\n";
        }

        if ($lines === '') {
            return '';
        }

        return "Clauses already indexed for this contract, for citation by number:\n" . $lines . "\n";
    }
}
