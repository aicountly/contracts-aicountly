<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Modules\Drive\StorageAdapter;
use App\Support\DomainException;
use App\Support\TenantContext;
use PDO;
use Throwable;
use ZipArchive;

/**
 * Plain text out of a contract document, for search, diffing and AI grounding.
 *
 * Three things depend on this and all three fail quietly when it lies. Search
 * finds the wrong contracts, a version diff shows changes that were never made,
 * and an AI answer cites a clause the document does not contain. So the rule
 * this class is built around is: never store text that was not actually
 * extracted. A scanned PDF yields nothing, and the honest record of that is
 * `is_scanned = true` and a null `extracted_text` — not an empty string, and
 * certainly not a guess.
 *
 * Everything is pure PHP with no Composer package and no shell-out. `pdftotext`
 * and Tesseract are not installed on the cPanel hosts this runs on, and
 * shelling out to a binary that may not exist is a worse failure than admitting
 * that a page needs OCR.
 */
final class TextExtractionService
{
    /**
     * Below this many characters per page, a PDF is treated as scanned.
     *
     * A page of a contract runs to a couple of thousand characters. Under
     * forty is a page whose only text is the header a scanner stamped on the
     * image — enough to look extracted, not enough to be usable, and exactly
     * the case that silently poisons a search index.
     */
    private const MIN_CHARS_PER_PAGE = 40;

    /** Under this, whatever came back is noise rather than content. */
    private const MIN_USEFUL_CHARS = 24;

    /** A contract this long is a data-entry accident; the column is not the limit, memory is. */
    private const MAX_TEXT_CHARS = 2_000_000;

    private DocumentService $documents;

    public function __construct(private PDO $pdo, ?StorageAdapter $storage = null)
    {
        $this->documents = new DocumentService($pdo, $storage);
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    /**
     * Extract one version's text and record the result.
     *
     * @return array{text: ?string, pages: ?int, scanned: bool}
     */
    public function extract(TenantContext $ctx, int $versionId): array
    {
        $version = $this->documents->findVersion($ctx, $versionId);
        if ($version === null) {
            throw DomainException::notFound('Document version not found.');
        }

        $bytes = $this->documents->readVersionBytes($ctx, $versionId);
        if ($bytes === null) {
            // Not recorded as a failed extraction: the file was unreachable,
            // not empty, and marking it now would stop the job ever retrying.
            throw DomainException::unavailable(
                'The stored file could not be read, so its text was not extracted.',
                'STORAGE_UNREACHABLE'
            );
        }

        $result = self::fromBytes($bytes, (string) $version['filename'], (string) $version['content_type']);

        $this->pdo->prepare(
            'UPDATE contract_document_versions
             SET extracted_text = ?, extracted_pages = ?, is_scanned = ?, text_extracted_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([
            $result['text'],
            $result['pages'],
            $result['scanned'] ? 'true' : 'false',
            $versionId,
            $ctx->environment,
            $ctx->cmpId,
        ]);

        return ['text' => $result['text'], 'pages' => $result['pages'], 'scanned' => $result['scanned']];
    }

    /**
     * Extraction with no database and no storage — the part worth testing.
     *
     * @return array{text: ?string, pages: ?int, scanned: bool, reason: ?string}
     */
    public static function fromBytes(string $bytes, string $filename, string $contentType = ''): array
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'txt'  => self::fromPlainText($bytes),
            'docx' => self::fromDocx($bytes),
            'pdf'  => self::fromPdf($bytes),
            default => [
                'text'    => null,
                'pages'   => null,
                'scanned' => false,
                'reason'  => 'No text extractor for .' . ($extension === '' ? 'unknown' : $extension) . ' files.',
            ],
        };
    }

    public static function supports(string $filename): bool
    {
        return in_array(strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), ['txt', 'docx', 'pdf'], true);
    }

    // -----------------------------------------------------------------------
    // Plain text
    // -----------------------------------------------------------------------

    /** @return array{text: ?string, pages: ?int, scanned: bool, reason: ?string} */
    private static function fromPlainText(string $bytes): array
    {
        // A .txt from a Windows desktop is very often CP-1252, and storing it
        // as though it were UTF-8 puts invalid byte sequences in a column that
        // PostgreSQL will reject outright.
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
            $bytes     = is_string($converted) ? $converted : '';
        }

        $bytes = self::stripBom($bytes);
        $text  = self::tidy($bytes);

        return [
            'text'    => $text === '' ? null : $text,
            'pages'   => null,
            'scanned' => false,
            'reason'  => $text === '' ? 'The file contained no text.' : null,
        ];
    }

    // -----------------------------------------------------------------------
    // Word
    // -----------------------------------------------------------------------

    /**
     * Text out of a .docx.
     *
     * A .docx is a zip; `word/document.xml` is the body. Read straight out of
     * the archive rather than through a document library, because the whole
     * product ships without Composer and a contract's paragraphs are all this
     * needs — styles, tracked changes and comments are someone else's problem.
     *
     * @return array{text: ?string, pages: ?int, scanned: bool, reason: ?string}
     */
    private static function fromDocx(string $bytes): array
    {
        if (! class_exists(ZipArchive::class)) {
            // Said plainly rather than crashed on: the operator can install
            // php-zip, and until they do the file is still stored and readable.
            return [
                'text'    => null,
                'pages'   => null,
                'scanned' => false,
                'reason'  => 'PHP is built without the zip extension, so Word documents cannot be read. Install php-zip.',
            ];
        }

        $temp = tempnam(sys_get_temp_dir(), 'ctr-docx');
        if ($temp === false) {
            return ['text' => null, 'pages' => null, 'scanned' => false, 'reason' => 'No temporary file available.'];
        }

        try {
            if (file_put_contents($temp, $bytes) === false) {
                return ['text' => null, 'pages' => null, 'scanned' => false, 'reason' => 'The file could not be staged for reading.'];
            }

            $zip = new ZipArchive();
            if ($zip->open($temp) !== true) {
                return ['text' => null, 'pages' => null, 'scanned' => false, 'reason' => 'That file is not a readable Word document.'];
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if (! is_string($xml) || $xml === '') {
                return ['text' => null, 'pages' => null, 'scanned' => false, 'reason' => 'The Word document has no body.'];
            }

            $text = self::xmlToText($xml);

            return [
                'text'    => $text === '' ? null : $text,
                'pages'   => null,
                'scanned' => false,
                'reason'  => $text === '' ? 'The Word document contained no text.' : null,
            ];
        } finally {
            @unlink($temp);
        }
    }

    /**
     * WordprocessingML to text.
     *
     * The structural tags carry the line breaks, so they are turned into
     * whitespace before the rest are stripped — otherwise every paragraph runs
     * into the next and a clause-by-clause diff becomes one enormous line.
     */
    private static function xmlToText(string $xml): string
    {
        $xml = preg_replace('#<w:(?:br|cr)\b[^>]*/?>#i', "\n", $xml) ?? $xml;
        $xml = preg_replace('#<w:tab\b[^>]*/?>#i', "\t", $xml) ?? $xml;
        $xml = preg_replace('#</w:p>#i', "\n", $xml) ?? $xml;
        $xml = preg_replace('#</w:tr>#i', "\n", $xml) ?? $xml;
        $xml = preg_replace('#</w:tc>#i', "\t", $xml) ?? $xml;

        // Instruction text (field codes such as PAGE or MERGEFIELD) is markup
        // the reader never sees; leaving it in would put "MERGEFIELD Party" in
        // the searchable body of every templated contract.
        $xml = preg_replace('#<w:instrText\b[^>]*>.*?</w:instrText>#is', '', $xml) ?? $xml;

        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return self::tidy($text);
    }

    // -----------------------------------------------------------------------
    // PDF
    // -----------------------------------------------------------------------

    /**
     * Embedded text out of a PDF, or an honest admission that there is none.
     *
     * Handles the two cases that account for nearly every contract PDF in
     * practice: an uncompressed content stream, and a FlateDecode one. A
     * scanned agreement is a page of images with no text objects at all, and a
     * PDF whose fonts have no usable encoding yields mojibake — both come out
     * below the per-page threshold and are reported as needing OCR rather than
     * stored as though they were the contract.
     *
     * @return array{text: ?string, pages: ?int, scanned: bool, reason: ?string}
     */
    private static function fromPdf(string $bytes): array
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            return ['text' => null, 'pages' => null, 'scanned' => false, 'reason' => 'That file is not a PDF.'];
        }

        $pages = self::countPdfPages($bytes);

        if (preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', $bytes) === 1) {
            // Password-protected. Not scanned — OCR would not help — so the
            // timestamp records that we looked and the text stays null.
            return ['text' => null, 'pages' => $pages, 'scanned' => false, 'reason' => 'The PDF is encrypted.'];
        }

        $text = self::tidy(self::pdfStreamText($bytes));

        if (mb_strlen($text) < self::MIN_USEFUL_CHARS) {
            return [
                'text'    => null,
                'pages'   => $pages,
                'scanned' => true,
                'reason'  => 'No text layer; this PDF needs OCR before it can be searched.',
            ];
        }

        $scanned = $pages !== null && mb_strlen($text) < $pages * self::MIN_CHARS_PER_PAGE;

        return [
            'text'    => $text,
            'pages'   => $pages,
            'scanned' => $scanned,
            'reason'  => $scanned ? 'Very little text for the page count; some pages likely need OCR.' : null,
        ];
    }

    private static function countPdfPages(string $bytes): ?int
    {
        $count = preg_match_all('#/Type\s*/Page[^s]#', $bytes);
        if (is_int($count) && $count > 0) {
            return $count;
        }

        // A linearised or object-stream PDF may not spell out every /Page, but
        // the page tree still declares its total.
        if (preg_match('#/Type\s*/Pages\b.*?/Count\s+(\d+)#s', $bytes, $m) === 1) {
            return max(1, (int) $m[1]);
        }

        return null;
    }

    /**
     * Concatenated text from every content stream in the file.
     *
     * Image XObjects are skipped explicitly: inflating a scan's JPEG data and
     * running a text scanner over it produces random parenthesised bytes, which
     * is exactly the invented text this class exists to avoid.
     */
    private static function pdfStreamText(string $bytes): string
    {
        $out    = [];
        $offset = 0;
        $length = strlen($bytes);

        while (($start = strpos($bytes, 'stream', $offset)) !== false) {
            $dictStart = max(0, $start - 900);
            $dict      = substr($bytes, $dictStart, $start - $dictStart);

            $bodyStart = $start + 6;
            if ($bodyStart < $length && $bytes[$bodyStart] === "\r") {
                $bodyStart++;
            }
            if ($bodyStart < $length && $bytes[$bodyStart] === "\n") {
                $bodyStart++;
            }

            $end = strpos($bytes, 'endstream', $bodyStart);
            if ($end === false) {
                break;
            }
            $offset = $end + 9;

            if (preg_match('#/Subtype\s*/Image|/Type\s*/XObject\s*/Subtype\s*/Image#', $dict) === 1) {
                continue;
            }

            $raw = substr($bytes, $bodyStart, $end - $bodyStart);
            if ($raw === '') {
                continue;
            }

            if (preg_match('#/Filter[^>]*?/FlateDecode#s', $dict) === 1) {
                $raw = self::inflate($raw);
                if ($raw === null) {
                    continue;
                }
            } elseif (preg_match('#/Filter\s*/#s', $dict) === 1) {
                // Some other filter (DCTDecode, LZW, JBIG2). Decoding it is
                // either an image or a codec this product has no business
                // implementing; either way there is no text to take.
                continue;
            }

            $piece = self::textFromContentStream($raw);
            if ($piece !== '') {
                $out[] = $piece;
            }

            if (array_sum(array_map('strlen', $out)) > self::MAX_TEXT_CHARS) {
                break;
            }
        }

        return implode("\n", $out);
    }

    private static function inflate(string $raw): ?string
    {
        foreach (['gzuncompress', 'gzinflate', 'gzdecode'] as $fn) {
            $decoded = @$fn($raw);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        // A stream that will not inflate is corrupt or uses a predictor this
        // reader does not implement. Skipped, not guessed at.
        return null;
    }

    /**
     * The strings a PDF content stream draws.
     *
     * Walks the stream rather than pattern-matching it, because a literal
     * string may itself contain unbalanced parentheses and `\)` escapes that a
     * regex reads as the end of the string.
     */
    private static function textFromContentStream(string $stream): string
    {
        $out    = '';
        $length = strlen($stream);
        $i      = 0;

        while ($i < $length) {
            $char = $stream[$i];

            if ($char === '(') {
                [$literal, $i] = self::readPdfLiteral($stream, $i + 1);
                $out .= $literal;
                continue;
            }

            if ($char === '<' && $i + 1 < $length && $stream[$i + 1] !== '<') {
                $close = strpos($stream, '>', $i + 1);
                if ($close === false) {
                    break;
                }
                $out .= self::decodeHexString(substr($stream, $i + 1, $close - $i - 1));
                $i = $close + 1;
                continue;
            }

            // Positioning operators are where a line ends. Without them every
            // page comes back as one unbroken run of words.
            if ($char === 'T' && $i + 1 < $length && in_array($stream[$i + 1], ['d', 'D', '*'], true)) {
                $out .= "\n";
                $i += 2;
                continue;
            }
            if ($char === 'E' && substr($stream, $i, 2) === 'ET') {
                $out .= "\n";
                $i += 2;
                continue;
            }

            $i++;
        }

        return $out;
    }

    /** @return array{0: string, 1: int} the decoded literal and the offset after it */
    private static function readPdfLiteral(string $stream, int $i): array
    {
        $out    = '';
        $depth  = 1;
        $length = strlen($stream);

        while ($i < $length) {
            $char = $stream[$i];

            if ($char === '\\') {
                $next = $i + 1 < $length ? $stream[$i + 1] : '';
                if ($next !== '' && ctype_digit($next)) {
                    $octal = '';
                    $i++;
                    while ($i < $length && strlen($octal) < 3 && ctype_digit($stream[$i])) {
                        $octal .= $stream[$i];
                        $i++;
                    }
                    $out .= chr(octdec($octal) % 256);
                    continue;
                }

                $out .= match ($next) {
                    'n'     => "\n",
                    'r'     => "\r",
                    't'     => "\t",
                    'b'     => "\x08",
                    'f'     => "\x0C",
                    "\n"    => '',
                    default => $next,
                };
                $i += 2;
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return [$out, $i + 1];
                }
            }

            $out .= $char;
            $i++;
        }

        return [$out, $i];
    }

    private static function decodeHexString(string $hex): string
    {
        $clean = preg_replace('/[^0-9a-fA-F]/', '', $hex) ?? '';
        if ($clean === '') {
            return '';
        }
        if (strlen($clean) % 2 === 1) {
            $clean .= '0';
        }

        $binary = @hex2bin($clean);
        if (! is_string($binary)) {
            return '';
        }

        // UTF-16BE with a byte-order mark is how a PDF spells non-ASCII text.
        if (str_starts_with($binary, "\xFE\xFF")) {
            $converted = @mb_convert_encoding(substr($binary, 2), 'UTF-8', 'UTF-16BE');

            return is_string($converted) ? $converted : '';
        }

        return $binary;
    }

    // -----------------------------------------------------------------------
    // Shared
    // -----------------------------------------------------------------------

    /**
     * Normalise whatever came out into something storable and diffable.
     *
     * Invalid UTF-8 is dropped rather than substituted: PostgreSQL rejects it
     * outright, and a replacement character in the middle of a clause is worse
     * than a missing byte because it looks like part of the text.
     */
    private static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = (string) @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);

        return mb_strlen($text) > self::MAX_TEXT_CHARS ? mb_substr($text, 0, self::MAX_TEXT_CHARS) : $text;
    }

    private static function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }

    /**
     * Run one queued extraction job.
     *
     * Kept here rather than in the worker so the queue runner stays a loop over
     * job rows and knows nothing about documents.
     */
    public function runQueued(TenantContext $ctx, int $versionId): bool
    {
        try {
            $this->extract($ctx, $versionId);

            return true;
        } catch (Throwable $e) {
            error_log('[contracts][text] extraction failed for version ' . $versionId . ': ' . $e->getMessage());

            return false;
        }
    }
}
