<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use Throwable;

/**
 * Date arithmetic for contract deadlines.
 *
 * Everything here is date-only and UTC. Contract deadlines are calendar facts —
 * "90 days notice before 31 March" does not move because a server is in a
 * different timezone — and mixing in a time-of-day is how a notice deadline
 * ends up a day out for half the estate.
 */
final class Dates
{
    public static function today(): string
    {
        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    public static function parse(?string $date): ?DateTimeImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(substr(trim($date), 0, 10) . ' 00:00:00');
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function addDays(?string $date, int $days): ?string
    {
        $parsed = self::parse($date);

        return $parsed?->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    public static function addMonths(?string $date, int $months): ?string
    {
        $parsed = self::parse($date);
        if ($parsed === null) {
            return null;
        }

        // "+1 month" from 31 January lands on 3 March in PHP. For a contract
        // term that is wrong: a monthly obligation starting on the 31st should
        // fall on the last day of a short month, not spill into the next one.
        $day       = (int) $parsed->format('j');
        $firstOf   = $parsed->modify('first day of this month');
        $target    = $firstOf->modify(($months >= 0 ? '+' : '') . $months . ' months');
        $lastDay   = (int) $target->format('t');

        return $target->setDate(
            (int) $target->format('Y'),
            (int) $target->format('n'),
            min($day, $lastDay)
        )->format('Y-m-d');
    }

    /** Whole days from $from to $to. Negative when $to is in the past. */
    public static function daysBetween(?string $from, ?string $to): ?int
    {
        $a = self::parse($from);
        $b = self::parse($to);
        if ($a === null || $b === null) {
            return null;
        }

        return (int) $a->diff($b)->format('%r%a');
    }

    /** Days from today until $date. Negative once it has passed. */
    public static function daysUntil(?string $date): ?int
    {
        return self::daysBetween(self::today(), $date);
    }

    public static function isPast(?string $date): bool
    {
        $days = self::daysUntil($date);

        return $days !== null && $days < 0;
    }

    /**
     * The notice deadline for a contract.
     *
     * Stored on the row rather than computed on read, because the nightly sweep
     * filters on it across every tenant and an index on a stored column is the
     * difference between a range seek and a full scan.
     */
    public static function noticeDeadline(?string $expiryDate, ?int $noticePeriodDays): ?string
    {
        if ($expiryDate === null || $noticePeriodDays === null || $noticePeriodDays <= 0) {
            return null;
        }

        return self::addDays($expiryDate, -$noticePeriodDays);
    }

    /** The next due date after $from for a recurrence. */
    public static function advance(string $from, string $frequency, ?int $customDays = null): ?string
    {
        return match ($frequency) {
            'daily'       => self::addDays($from, 1),
            'weekly'      => self::addDays($from, 7),
            'fortnightly' => self::addDays($from, 14),
            'monthly'     => self::addMonths($from, 1),
            'quarterly'   => self::addMonths($from, 3),
            'half_yearly' => self::addMonths($from, 6),
            'annual'      => self::addMonths($from, 12),
            'custom'      => $customDays !== null && $customDays > 0 ? self::addDays($from, $customDays) : null,
            default       => null,
        };
    }

    /** Months in a recurrence, for term calculations. Null for sub-monthly cycles. */
    public static function frequencyMonths(string $frequency): ?int
    {
        return match ($frequency) {
            'monthly'     => 1,
            'quarterly'   => 3,
            'half_yearly' => 6,
            'annual'      => 12,
            'biennial'    => 24,
            default       => null,
        };
    }

    /**
     * Parse a "90,60,30" reminder ladder into descending unique day offsets.
     *
     * @return list<int>
     */
    public static function reminderLadder(?string $csv, array $fallback = [30, 7, 1]): array
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $csv)), static fn (string $v): bool => $v !== '');
        $days  = [];
        foreach ($parts as $part) {
            if (preg_match('/^\d{1,4}$/', $part)) {
                $days[] = (int) $part;
            }
        }

        if ($days === []) {
            return $fallback;
        }

        $days = array_values(array_unique($days));
        rsort($days);

        return $days;
    }
}
