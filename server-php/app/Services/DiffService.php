<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\DomainException;
use App\Support\TenantContext;
use PDO;

/**
 * The redline engine: what changed between two versions of a document, decided
 * arithmetically.
 *
 * There is no model in this file and there must never be one. A redline is
 * evidence — it is read in a negotiation and quoted in a dispute — so it has to
 * be reproducible, explainable line by line, and identical every time it is
 * asked for. An LLM can then be asked what the change *means*
 * (AiAnalysisService does exactly that, over this output), but what changed is
 * settled here first.
 *
 * The shape of the algorithm:
 *
 *   1. Both sides are split into paragraphs, with internal wrapping normalised
 *      so a re-flowed line is not reported as a rewrite.
 *   2. Identical head and tail paragraphs are trimmed off — most redlines touch
 *      a small part of a long agreement, and this is what keeps the matrix
 *      small in the common case.
 *   3. What is left goes through a real LCS. Its direction matrix is stored one
 *      byte per cell rather than one PHP int, which is the difference between
 *      a megabyte and a hundred for a thousand-paragraph document.
 *   4. Each changed paragraph pair is diffed again at word level, so the UI can
 *      strike out three words instead of a whole clause.
 *
 * Every step is bounded. A document past the cap is compared paragraph-only
 * rather than allowed to spend an unbounded amount of a request on a matrix.
 */
final class DiffService
{
    /** Text past this is clipped before comparing. Roughly a 150-page agreement. */
    public const MAX_INPUT_BYTES = 400000;

    /** Paragraph matrix cells. One byte each, so this is the memory bound too. */
    private const MAX_MATRIX_CELLS = 1000000;

    /** Word matrix cells, per changed paragraph pair. */
    private const MAX_WORD_CELLS = 250000;

    /** Segments kept in the result. A diff longer than this is a rewrite, not a redline. */
    private const MAX_SEGMENTS = 2000;

    /** Characters kept per side of a segment. */
    private const MAX_SEGMENT_TEXT = 8000;

    /**
     * What each category is recognised by.
     *
     * Substrings rather than whole words on purpose: "terminat" catches
     * terminate, termination and terminated without three entries, and a
     * contract that says "indemnification" should match "indemnif".
     */
    private const CATEGORY_TERMS = [
        'liability' => [
            'liability', 'liabilities', 'indemnif', 'indemnity', 'hold harmless',
            'consequential', 'aggregate liability', 'damages', 'limitation of liability',
        ],
        'termination' => [
            'terminat', 'for convenience', 'material breach', 'cure period',
            'notice period', 'right to cancel', 'suspend the services',
        ],
        'renewal' => [
            'renew', 'evergreen', 'extend the term', 'extension of the term',
            'further period', 'roll over',
        ],
        'payment_terms' => [
            'payment term', 'invoice', 'payable within', 'due within', 'net 30',
            'net 45', 'net 60', 'late payment', 'overdue', 'billing cycle',
        ],
        'governing_law' => [
            'governing law', 'governed by', 'jurisdiction', 'arbitrat',
            'courts of', 'seat of', 'venue',
        ],
    ];

    /**
     * A monetary figure.
     *
     * Deliberately narrow: a bare number is a quantity, a clause number or a
     * day count far more often than it is money, and a diff that calls every
     * numeric edit an amount change is a diff nobody trusts.
     */
    private const MONEY = '/(?:[₹$€£]\s?\d[\d,]*(?:\.\d+)?)'
        . '|(?:\b(?:INR|USD|EUR|GBP|AED|SGD|Rs\.?)\s?\d[\d,]*(?:\.\d+)?)'
        . '|(?:\b\d[\d,]*(?:\.\d+)?\s?(?:lakh|lakhs|crore|crores|million|billion)\b)'
        . '|(?:\b\d{1,3}(?:,\d{2,3})+(?:\.\d+)?\b)/iu';

    /** A calendar date, or a period expressed in days, months or years. */
    private const DATE = '/\b\d{4}-\d{2}-\d{2}\b'
        . '|\b\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}\b'
        . '|\b\d{1,2}(?:st|nd|rd|th)?\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?,?\s+\d{4}\b'
        . '|\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{1,2},?\s+\d{4}\b'
        . '|\b\d{1,4}\s+(?:day|days|month|months|year|years)\b/iu';

    private ActivityService $activity;

    public function __construct(private PDO $pdo)
    {
        $this->activity = new ActivityService($pdo);
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // The diff
    // -----------------------------------------------------------------------

    /**
     * Compare two documents.
     *
     * @return array{
     *     segments: list<array<string,mixed>>,
     *     stats: array{added: int, removed: int, changed: int, similarity: float},
     *     mode: string,
     *     truncated: bool
     * }
     */
    public function diff(string $base, string $target): array
    {
        $truncated = false;
        if (strlen($base) > self::MAX_INPUT_BYTES) {
            $base      = substr($base, 0, self::MAX_INPUT_BYTES);
            $truncated = true;
        }
        if (strlen($target) > self::MAX_INPUT_BYTES) {
            $target    = substr($target, 0, self::MAX_INPUT_BYTES);
            $truncated = true;
        }

        $a = self::paragraphs($base);
        $b = self::paragraphs($target);

        // Identical head and tail come out before the matrix is sized. A
        // one-clause amendment to a fifty-page agreement is the normal case,
        // and without this it would still cost the full n*m.
        $head = 0;
        $limit = min(count($a), count($b));
        while ($head < $limit && $a[$head] === $b[$head]) {
            $head++;
        }

        $tail  = 0;
        $limit = min(count($a), count($b)) - $head;
        while ($tail < $limit && $a[count($a) - 1 - $tail] === $b[count($b) - 1 - $tail]) {
            $tail++;
        }

        $midA = array_slice($a, $head, count($a) - $head - $tail);
        $midB = array_slice($b, $head, count($b) - $head - $tail);

        $mode = 'lcs';
        if (count($midA) * count($midB) > self::MAX_MATRIX_CELLS) {
            // Past the cap the honest answer is "this whole region differs".
            // Guessing at alignment with a cheaper heuristic would produce a
            // redline that looks precise and is not.
            $mode = 'paragraph_only';
            $ops  = [];
            foreach ($midA as $text) {
                $ops[] = ['op' => 'delete', 'text' => $text];
            }
            foreach ($midB as $text) {
                $ops[] = ['op' => 'insert', 'text' => $text];
            }
        } else {
            $ops = self::lcsOps($midA, $midB);
        }

        $all = [];
        for ($i = 0; $i < $head; $i++) {
            $all[] = ['op' => 'equal', 'text' => $a[$i]];
        }
        foreach ($ops as $op) {
            $all[] = $op;
        }
        for ($i = count($a) - $tail; $i < count($a); $i++) {
            $all[] = ['op' => 'equal', 'text' => $a[$i]];
        }

        $segments = self::segmentsFrom($all, $mode === 'lcs');
        $stats    = self::statsFor($segments, $a, $b);

        if (count($segments) > self::MAX_SEGMENTS) {
            $segments  = array_slice($segments, 0, self::MAX_SEGMENTS);
            $truncated = true;
        }

        return [
            'segments'  => array_map(self::clipSegment(...), $segments),
            'stats'     => $stats,
            'mode'      => $mode,
            'truncated' => $truncated,
        ];
    }

    /**
     * Say what kind of change each segment is.
     *
     * This is what lets the negotiation screen say "the liability language
     * changed" the moment a redline lands, with no model in the path and no
     * cost per comparison. A clause keyword beats a bare figure: when the cap
     * inside a liability clause moves, the useful sentence is that liability
     * changed, not that a number did.
     *
     * @param list<array<string,mixed>> $segments
     * @return list<array<string,mixed>>
     */
    public function classifyChanges(array $segments): array
    {
        $changes = [];

        foreach ($segments as $index => $segment) {
            $type = (string) ($segment['type'] ?? 'equal');
            if ($type === 'equal') {
                continue;
            }

            $baseText   = (string) ($segment['base'] ?? '');
            $targetText = (string) ($segment['target'] ?? '');

            [$category, $matched] = self::categorise($baseText, $targetText);

            $changes[] = [
                'segment'       => $index,
                'type'          => $type,
                'category'      => $category,
                'matched'       => $matched,
                'summary'       => self::summaryFor($type, $category),
                'base_excerpt'  => self::excerpt($baseText),
                'target_excerpt' => self::excerpt($targetText),
            ];
        }

        return $changes;
    }

    // -----------------------------------------------------------------------
    // Cached comparison of two stored versions
    // -----------------------------------------------------------------------

    /**
     * Compare two document versions, computing the diff at most once.
     *
     * The cache can never go stale because its inputs cannot change: a document
     * version is a stored file plus the text extracted from it, and both are
     * written once. That is also why a version whose text has not been
     * extracted yet is refused rather than compared as empty — caching that
     * answer would pin "the whole document was added" forever, and nothing
     * would ever recompute it.
     *
     * @return array<string,mixed>
     */
    public function compareVersions(TenantContext $ctx, int $baseVersionId, int $targetVersionId): array
    {
        if ($baseVersionId === $targetVersionId) {
            throw DomainException::badRequest('Choose two different versions to compare.');
        }

        $base   = $this->versionOrFail($ctx, $baseVersionId);
        $target = $this->versionOrFail($ctx, $targetVersionId);

        $contractId = $base['contract_id'] === null ? null : (int) $base['contract_id'];
        if ($contractId === null || (int) ($target['contract_id'] ?? 0) !== $contractId) {
            throw DomainException::badRequest(
                'Both versions must belong to the same contract.',
                'VERSIONS_NOT_COMPARABLE'
            );
        }

        $cached = $this->cachedComparison($ctx, $baseVersionId, $targetVersionId);
        if ($cached !== null) {
            return $cached + ['cached' => true];
        }

        foreach ([$base, $target] as $version) {
            if ($version['extracted_text'] === null) {
                throw DomainException::conflict(
                    sprintf(
                        'Version %d has no extracted text yet, so it cannot be compared.',
                        (int) $version['version_no']
                    ),
                    'TEXT_NOT_EXTRACTED'
                );
            }
        }

        $diff    = $this->diff((string) $base['extracted_text'], (string) $target['extracted_text']);
        $changes = $this->classifyChanges($diff['segments']);

        $this->pdo->prepare(
            'INSERT INTO contract_version_comparisons
             (environment, cmp_id, contract_id, base_version_id, target_version_id,
              diff_json, stats_json, generated_by)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?)
             ON CONFLICT (base_version_id, target_version_id) DO NOTHING'
        )->execute([
            $ctx->environment,
            $ctx->cmpId,
            $contractId,
            $baseVersionId,
            $targetVersionId,
            json_encode([
                'segments'  => $diff['segments'],
                'changes'   => $changes,
                'mode'      => $diff['mode'],
                'truncated' => $diff['truncated'],
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            json_encode($diff['stats'], JSON_UNESCAPED_SLASHES),
            $ctx->uuid,
        ]);

        $this->activity->record(
            $ctx,
            $contractId,
            'version.compared',
            sprintf('Version %d compared with version %d', (int) $base['version_no'], (int) $target['version_no']),
            [
                'base_version_id'   => $baseVersionId,
                'target_version_id' => $targetVersionId,
                'changed'           => $diff['stats']['changed'],
                'added'             => $diff['stats']['added'],
                'removed'           => $diff['stats']['removed'],
            ]
        );

        // Read back rather than assembling the payload here: a concurrent
        // request may have won the ON CONFLICT, and both callers must see the
        // one stored comparison rather than two equal-but-separate answers.
        $stored = $this->cachedComparison($ctx, $baseVersionId, $targetVersionId);
        if ($stored === null) {
            throw new DomainException('The comparison could not be stored.', 'COMPARE_FAILED', 500);
        }

        return $stored + ['cached' => false];
    }

    // -----------------------------------------------------------------------
    // Internals — storage
    // -----------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function cachedComparison(TenantContext $ctx, int $baseVersionId, int $targetVersionId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT c.id, c.contract_id, c.base_version_id, c.target_version_id,
                    c.diff_json, c.stats_json, c.ai_explanation, c.ai_findings,
                    c.generated_by, c.created_at,
                    b.version_no AS base_version_no, b.filename AS base_filename,
                    b.version_status AS base_version_status,
                    t.version_no AS target_version_no, t.filename AS target_filename,
                    t.version_status AS target_version_status
             FROM contract_version_comparisons c
             JOIN contract_document_versions b ON b.id = c.base_version_id
             JOIN contract_document_versions t ON t.id = c.target_version_id
             WHERE c.environment = ? AND c.cmp_id = ?
               AND c.base_version_id = ? AND c.target_version_id = ?
             LIMIT 1'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $baseVersionId, $targetVersionId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            return null;
        }

        $diff  = self::decodeJson($row['diff_json']);
        $stats = self::decodeJson($row['stats_json']);

        return [
            'id'                => (int) $row['id'],
            'contract_id'       => (int) $row['contract_id'],
            'base_version_id'   => (int) $row['base_version_id'],
            'target_version_id' => (int) $row['target_version_id'],
            'base' => [
                'version_no' => (int) $row['base_version_no'],
                'filename'   => $row['base_filename'],
                'status'     => $row['base_version_status'],
            ],
            'target' => [
                'version_no' => (int) $row['target_version_no'],
                'filename'   => $row['target_filename'],
                'status'     => $row['target_version_status'],
            ],
            'segments'       => is_array($diff['segments'] ?? null) ? $diff['segments'] : [],
            'changes'        => is_array($diff['changes'] ?? null) ? $diff['changes'] : [],
            'mode'           => (string) ($diff['mode'] ?? 'lcs'),
            'truncated'      => (bool) ($diff['truncated'] ?? false),
            'stats'          => $stats,
            'ai_explanation' => $row['ai_explanation'],
            'ai_findings'    => self::decodeJson($row['ai_findings']),
            'generated_by'   => $row['generated_by'],
            'created_at'     => $row['created_at'],
        ];
    }

    /**
     * A version row, scoped to the caller's tenant.
     *
     * The join to contract_documents is what makes `contract_id` trustworthy:
     * the version table carries the tenant columns but not the owner, and the
     * comparison row has to be filed against the right contract.
     *
     * @return array<string,mixed>
     */
    private function versionOrFail(TenantContext $ctx, int $versionId): array
    {
        $st = $this->pdo->prepare(
            'SELECT v.id, v.version_no, v.version_status, v.filename, v.extracted_text,
                    d.contract_id
             FROM contract_document_versions v
             JOIN contract_documents d ON d.id = v.document_id
             WHERE v.id = ? AND v.environment = ? AND v.cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$versionId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Document version not found.');
        }

        return $row;
    }

    // -----------------------------------------------------------------------
    // Internals — the algorithm
    // -----------------------------------------------------------------------

    /**
     * Split a document into comparable paragraphs.
     *
     * Whitespace inside a paragraph is normalised because the same clause
     * extracted from a PDF and from a DOCX wraps differently, and a diff that
     * reports every re-flowed line as a rewrite is noise. Falling back to line
     * splitting matters for the same reason: plenty of extracted text arrives
     * with no blank line in it at all.
     *
     * @return list<string>
     */
    private static function paragraphs(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }

        $blocks = self::cleanBlocks(preg_split('/\n[ \t]*\n+/', $text) ?: []);

        if (count($blocks) <= 1 && str_contains($text, "\n")) {
            $blocks = self::cleanBlocks(explode("\n", $text));
        }

        return $blocks;
    }

    /** @param list<string> $raw @return list<string> */
    private static function cleanBlocks(array $raw): array
    {
        $out = [];
        foreach ($raw as $block) {
            $block = trim((string) preg_replace('/\s+/u', ' ', $block));
            if ($block !== '') {
                $out[] = $block;
            }
        }

        return $out;
    }

    /**
     * Longest common subsequence, returning the edit script.
     *
     * The direction matrix is a string per row — one byte per cell instead of
     * the ~80 a PHP int array costs — so a thousand-by-thousand comparison is a
     * megabyte rather than eighty. The length rows roll, since only the
     * previous one is ever read.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{op: string, text: string}>
     */
    private static function lcsOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 && $m === 0) {
            return [];
        }
        if ($n === 0) {
            return array_map(static fn (string $t): array => ['op' => 'insert', 'text' => $t], $b);
        }
        if ($m === 0) {
            return array_map(static fn (string $t): array => ['op' => 'delete', 'text' => $t], $a);
        }

        $previous  = array_fill(0, $m + 1, 0);
        $direction = [];

        for ($i = 1; $i <= $n; $i++) {
            $current = array_fill(0, $m + 1, 0);
            $row     = '';
            $left    = $a[$i - 1];

            for ($j = 1; $j <= $m; $j++) {
                if ($left === $b[$j - 1]) {
                    $current[$j] = $previous[$j - 1] + 1;
                    $row .= 'd';
                } elseif ($previous[$j] >= $current[$j - 1]) {
                    $current[$j] = $previous[$j];
                    $row .= 'u';
                } else {
                    $current[$j] = $current[$j - 1];
                    $row .= 'l';
                }
            }

            $direction[] = $row;
            $previous    = $current;
        }

        $ops = [];
        $i   = $n;
        $j   = $m;

        while ($i > 0 && $j > 0) {
            $step = $direction[$i - 1][$j - 1];
            if ($step === 'd') {
                $ops[] = ['op' => 'equal', 'text' => $a[--$i]];
                $j--;
            } elseif ($step === 'u') {
                $ops[] = ['op' => 'delete', 'text' => $a[--$i]];
            } else {
                $ops[] = ['op' => 'insert', 'text' => $b[--$j]];
            }
        }
        while ($i > 0) {
            $ops[] = ['op' => 'delete', 'text' => $a[--$i]];
        }
        while ($j > 0) {
            $ops[] = ['op' => 'insert', 'text' => $b[--$j]];
        }

        return array_reverse($ops);
    }

    /**
     * Group the edit script into display segments.
     *
     * A deletion immediately followed by an insertion is one rewrite, not two
     * unrelated events, so the two are folded into a `replace` and diffed again
     * at word level — that is what turns "this clause changed" into "these
     * three words changed".
     *
     * @param list<array{op: string, text: string}> $ops
     * @return list<array<string,mixed>>
     */
    private static function segmentsFrom(array $ops, bool $refineWords): array
    {
        $runs = [];
        foreach ($ops as $op) {
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['op'] === $op['op']) {
                $runs[$last]['texts'][] = $op['text'];

                continue;
            }
            $runs[] = ['op' => $op['op'], 'texts' => [$op['text']]];
        }

        $segments = [];
        $count    = count($runs);

        for ($i = 0; $i < $count; $i++) {
            $run  = $runs[$i];
            $text = implode("\n\n", $run['texts']);

            if ($run['op'] === 'equal') {
                $segments[] = [
                    'type'   => 'equal',
                    'base'   => $text,
                    'target' => $text,
                    'base_paragraphs'   => count($run['texts']),
                    'target_paragraphs' => count($run['texts']),
                ];

                continue;
            }

            if ($run['op'] === 'delete' && $i + 1 < $count && $runs[$i + 1]['op'] === 'insert') {
                $targetTexts = $runs[$i + 1]['texts'];
                $targetText  = implode("\n\n", $targetTexts);
                $i++;

                $segment = [
                    'type'   => 'replace',
                    'base'   => $text,
                    'target' => $targetText,
                    'base_paragraphs'   => count($run['texts']),
                    'target_paragraphs' => count($targetTexts),
                ];

                if ($refineWords) {
                    $words = self::wordDiff($text, $targetText);
                    if ($words !== null) {
                        $segment['words'] = $words;
                    }
                }

                $segments[] = $segment;

                continue;
            }

            $segments[] = [
                'type'   => $run['op'],
                'base'   => $run['op'] === 'delete' ? $text : '',
                'target' => $run['op'] === 'insert' ? $text : '',
                'base_paragraphs'   => $run['op'] === 'delete' ? count($run['texts']) : 0,
                'target_paragraphs' => $run['op'] === 'insert' ? count($run['texts']) : 0,
            ];
        }

        return $segments;
    }

    /**
     * Word-level runs inside one rewritten paragraph.
     *
     * Null when the pair is too large to refine: the paragraph-level answer is
     * already correct, and the word diff is a presentation nicety that must
     * never be what makes a request slow.
     *
     * @return list<array{type: string, text: string}>|null
     */
    private static function wordDiff(string $base, string $target): ?array
    {
        $a = preg_split('/\s+/u', trim($base), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('/\s+/u', trim($target), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($a === [] || $b === [] || count($a) * count($b) > self::MAX_WORD_CELLS) {
            return null;
        }

        $runs = [];
        foreach (self::lcsOps($a, $b) as $op) {
            $type = $op['op'] === 'equal' ? 'equal' : $op['op'];
            $last = count($runs) - 1;

            if ($last >= 0 && $runs[$last]['type'] === $type) {
                $runs[$last]['text'] .= ' ' . $op['text'];

                continue;
            }
            $runs[] = ['type' => $type, 'text' => $op['text']];
        }

        return $runs;
    }

    /**
     * @param list<array<string,mixed>> $segments
     * @param list<string>              $a
     * @param list<string>              $b
     * @return array{added: int, removed: int, changed: int, similarity: float}
     */
    private static function statsFor(array $segments, array $a, array $b): array
    {
        $added   = 0;
        $removed = 0;
        $changed = 0;
        $common  = 0;

        foreach ($segments as $segment) {
            switch ($segment['type']) {
                case 'equal':
                    $common += strlen((string) $segment['base']);
                    break;

                case 'insert':
                    $added += (int) $segment['target_paragraphs'];
                    break;

                case 'delete':
                    $removed += (int) $segment['base_paragraphs'];
                    break;

                default:
                    $changed += max((int) $segment['base_paragraphs'], (int) $segment['target_paragraphs']);
                    foreach ($segment['words'] ?? [] as $run) {
                        if ($run['type'] === 'equal') {
                            $common += strlen((string) $run['text']);
                        }
                    }
            }
        }

        $total = strlen(implode("\n\n", $a)) + strlen(implode("\n\n", $b));

        return [
            'added'      => $added,
            'removed'    => $removed,
            'changed'    => $changed,
            // Dice coefficient over characters the two documents share: 1.0 for
            // an unchanged document, 0.0 for two with nothing in common. It is
            // reported rather than a raw edit distance because "94% unchanged"
            // is a sentence a reviewer can act on.
            'similarity' => $total === 0 ? 1.0 : round((2 * $common) / $total, 4),
        ];
    }

    /** @param array<string,mixed> $segment @return array<string,mixed> */
    private static function clipSegment(array $segment): array
    {
        foreach (['base', 'target'] as $side) {
            if (strlen((string) $segment[$side]) > self::MAX_SEGMENT_TEXT) {
                $segment[$side] = mb_substr((string) $segment[$side], 0, self::MAX_SEGMENT_TEXT) . '…';
            }
        }

        return $segment;
    }

    // -----------------------------------------------------------------------
    // Internals — classification
    // -----------------------------------------------------------------------

    /** @return array{0: string, 1: list<string>} */
    private static function categorise(string $base, string $target): array
    {
        $haystack = mb_strtolower($base . "\n" . $target);

        $best      = 'other';
        $bestScore = 0;
        $matched   = [];

        foreach (self::CATEGORY_TERMS as $category => $terms) {
            $hits = [];
            foreach ($terms as $term) {
                if (str_contains($haystack, $term)) {
                    $hits[] = $term;
                }
            }

            // Strictly greater, so the declaration order above breaks ties —
            // liability before termination before renewal, and so on down.
            if (count($hits) > $bestScore) {
                $best      = $category;
                $bestScore = count($hits);
                $matched   = $hits;
            }
        }

        if ($bestScore > 0) {
            return [$best, $matched];
        }

        $money = self::changedTokens(self::MONEY, $base, $target);
        if ($money !== []) {
            return ['amount', $money];
        }

        $dates = self::changedTokens(self::DATE, $base, $target);
        if ($dates !== []) {
            return ['date', $dates];
        }

        return ['other', []];
    }

    /**
     * Tokens matching $pattern that are not on both sides.
     *
     * Comparing the two sets rather than merely finding a match is what stops a
     * clause being reported as an amount change when the amount is the one
     * thing in it that stayed the same.
     *
     * @return list<string>
     */
    private static function changedTokens(string $pattern, string $base, string $target): array
    {
        preg_match_all($pattern, $base, $before);
        preg_match_all($pattern, $target, $after);

        $normalise = static fn (array $found): array => array_map(
            static fn (string $token): string => strtolower(preg_replace('/\s+/u', '', $token) ?? $token),
            $found[0] ?? []
        );

        $before = $normalise($before);
        $after  = $normalise($after);

        $changed = array_merge(
            array_diff($before, $after),
            array_diff($after, $before)
        );

        return array_values(array_unique($changed));
    }

    private static function summaryFor(string $type, string $category): string
    {
        $subject = match ($category) {
            'amount'         => 'a monetary amount',
            'date'           => 'a date or period',
            'liability'      => 'the liability language',
            'termination'    => 'the termination language',
            'renewal'        => 'the renewal language',
            'payment_terms'  => 'the payment terms',
            'governing_law'  => 'the governing law or forum',
            default          => 'wording',
        };

        return match ($type) {
            'insert' => 'A paragraph covering ' . $subject . ' was added.',
            'delete' => 'A paragraph covering ' . $subject . ' was removed.',
            default  => ucfirst($subject) . ' changed.',
        };
    }

    private static function excerpt(string $text): string
    {
        return mb_substr(trim($text), 0, 400);
    }

    private static function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
