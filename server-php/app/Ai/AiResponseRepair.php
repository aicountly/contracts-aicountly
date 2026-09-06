<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Recovers the JSON out of a model reply that was asked for JSON.
 *
 * Even with structured output turned on, replies arrive wrapped in a ```json
 * fence, preceded by "Here is the extraction:", or carrying a trailing comma
 * after the last array element. All three are punctuation problems around
 * intact data, and re-running the call to fix punctuation costs a second of
 * the user's time and another paid request.
 *
 * The rule this class keeps: it repairs syntax, never content. It will drop a
 * fence, discard prose outside the outermost braces, and remove a comma that
 * precedes a closing bracket. It will not close an unterminated object, invent
 * a missing value, or truncate to the last complete field — a half-read
 * contract extraction that decodes cleanly is far more dangerous than one that
 * fails, because nothing downstream can tell it was cut short. When the text
 * cannot be recovered by punctuation alone the answer is null, and the caller
 * retries or reports the failure honestly.
 */
final class AiResponseRepair
{
    /**
     * The decoded object or list, or null when the text cannot be recovered.
     *
     * @return array<mixed>|null
     */
    public static function decode(string $raw): ?array
    {
        $json = self::extract($raw);
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** The repaired JSON text, or null when nothing in $raw decodes. */
    public static function extract(string $raw): ?string
    {
        $raw = trim(str_replace("\u{FEFF}", '', $raw));
        if ($raw === '') {
            return null;
        }

        foreach (self::candidates($raw) as $candidate) {
            $balanced = self::balanced($candidate);
            if ($balanced === null) {
                continue;
            }

            if (self::decodes($balanced)) {
                return $balanced;
            }

            $stripped = self::stripTrailingCommas($balanced);
            if ($stripped !== $balanced && self::decodes($stripped)) {
                return $stripped;
            }
        }

        return null;
    }

    /**
     * The whole reply first, then each fenced block.
     *
     * Whole-reply first because a well-behaved response needs no repair at all
     * and should cost one json_decode. Fenced blocks after, in order, because a
     * model that explains itself before answering puts the answer in the fence.
     *
     * @return list<string>
     */
    private static function candidates(string $raw): array
    {
        $out = [$raw];

        if (preg_match_all('/```[A-Za-z0-9_-]*[ \t]*\r?\n(.*?)```/s', $raw, $matches) > 0) {
            foreach ($matches[1] as $block) {
                $block = trim($block);
                if ($block !== '') {
                    $out[] = $block;
                }
            }
        }

        // A reply cut off at the token limit opens a fence and never closes it.
        // The content is still worth a try; balanced() decides whether enough
        // of it arrived.
        if (preg_match('/```[A-Za-z0-9_-]*[ \t]*\r?\n(.*)$/s', $raw, $tail) === 1) {
            $block = trim($tail[1]);
            if ($block !== '' && ! in_array($block, $out, true)) {
                $out[] = $block;
            }
        }

        return $out;
    }

    /**
     * The outermost balanced {...} or [...] in $text.
     *
     * Scans with string and escape awareness, so a brace inside a clause quote
     * ("the Parties agree {as set out}") does not shift the depth count.
     */
    private static function balanced(string $text): ?string
    {
        $length = strlen($text);
        $start  = null;
        $open   = '';

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] === '{' || $text[$i] === '[') {
                $start = $i;
                $open  = $text[$i];
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $close    = $open === '{' ? '}' : ']';
        $depth    = 0;
        $inString = false;
        $escaped  = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        // Never closed: the reply was truncated. Deliberately not repaired —
        // see the class docblock.
        return null;
    }

    /** Remove a comma that has nothing but whitespace between it and a closing bracket. */
    private static function stripTrailingCommas(string $json): string
    {
        $out      = '';
        $length   = strlen($json);
        $inString = false;
        $escaped  = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                $out .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                $out .= $char;
                continue;
            }

            if ($char === '}' || $char === ']') {
                $trimmed = rtrim($out);
                if ($trimmed !== '' && str_ends_with($trimmed, ',')) {
                    $out = substr($trimmed, 0, -1);
                }
            }

            $out .= $char;
        }

        return $out;
    }

    private static function decodes(string $json): bool
    {
        json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
