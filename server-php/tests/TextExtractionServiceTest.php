<?php

declare(strict_types=1);

/**
 * The .docx reader's guard against an oversized or over-compressed
 * word/document.xml entry.
 *
 * Pure logic, no database: fromBytes() takes no PDO and touches no storage,
 * which is exactly why the class's own docblock calls it "the part worth
 * testing" — see DocumentServiceTest.php for the extraction round trip through
 * a real upload.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\TextExtractionService;

const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

/**
 * A minimal .docx whose word/document.xml entry decompresses to $size bytes
 * of one repeated character — the shape of a zip bomb, not a real contract,
 * but Deflate compresses it just as well either way.
 */
function bomb_docx(int $size): string
{
    $path = tempnam(sys_get_temp_dir(), 'ctr-docx-bomb');
    $zip  = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'
    );
    // Wrapped in one <w:t> so a version of the reader that read further than
    // the guard would still see it as ordinary paragraph text, not markup.
    $zip->addFromString('word/document.xml', '<w:t>' . str_repeat('A', $size) . '</w:t>');
    $zip->close();

    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

/** A real, small .docx — the case the guard must never touch. */
function real_docx(string $paragraph): string
{
    $path = tempnam(sys_get_temp_dir(), 'ctr-docx-real');
    $zip  = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'
    );
    $zip->addFromString(
        'word/document.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . '<w:p><w:r><w:t>' . htmlspecialchars($paragraph, ENT_XML1) . '</w:t></w:r></w:p>'
        . '</w:body></w:document>'
    );
    $zip->close();

    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

// ---------------------------------------------------------------------------
// Oversized entry, refused before it is decompressed
// ---------------------------------------------------------------------------

// 30,000,000 bytes uncompressed is past MAX_DOCX_ENTRY_BYTES on its own,
// regardless of how well it compresses.
$oversized = TextExtractionService::fromBytes(bomb_docx(30_000_000), 'bomb.docx', DOCX_MIME);

assert_null($oversized['text'], 'an entry past the size cap yields no text');
assert_false($oversized['scanned'], 'refusing on size is not the same claim as "needs OCR"');
assert_contains('too large', (string) $oversized['reason'], 'the refusal says why, for whoever reads the log');

// ---------------------------------------------------------------------------
// Under the size cap but an absurd compression ratio — still refused
// ---------------------------------------------------------------------------

// 15,000,000 repeated bytes sits under MAX_DOCX_ENTRY_BYTES (20,000,000) but
// deflates at three-plus orders of magnitude, well past the ratio a real,
// tag-heavy WordprocessingML document ever reaches.
$ratioBomb = bomb_docx(15_000_000);
$zipCheck  = new ZipArchive();
$tmpCheck  = tempnam(sys_get_temp_dir(), 'ctr-docx-check');
file_put_contents($tmpCheck, $ratioBomb);
$zipCheck->open($tmpCheck);
$stat = $zipCheck->statName('word/document.xml');
$zipCheck->close();
@unlink($tmpCheck);
assert_true(
    $stat !== false && (int) $stat['size'] < 20_000_000 && (int) $stat['size'] / max(1, (int) $stat['comp_size']) > 200,
    'fixture sanity: under the byte cap, over the ratio cap'
);

$ratioResult = TextExtractionService::fromBytes($ratioBomb, 'bomb2.docx', DOCX_MIME);
assert_null($ratioResult['text'], 'a compression ratio no real document reaches is refused even under the byte cap');
assert_contains('too large', (string) $ratioResult['reason'], 'refused for the same, legible reason');

// ---------------------------------------------------------------------------
// A real document is unaffected
// ---------------------------------------------------------------------------

$real = TextExtractionService::fromBytes(
    real_docx('This Agreement is made between Acme Industries and Globex Corporation.'),
    'agreement.docx',
    DOCX_MIME
);
assert_contains('Acme Industries', (string) $real['text'], 'an ordinary document still extracts normally');
assert_null($real['reason'], 'and carries no refusal reason');

t_done('TextExtractionServiceTest');
