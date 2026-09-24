<?php
declare(strict_types=1);

final class CashierReportingPeriod
{
    public const TIMEZONE = 'Asia/Manila';

    public static function resolve(
        string $period,
        ?DateTimeImmutable $now = null,
        mixed $month = null,
        mixed $year = null
    ): array {
        if (!in_array($period, ['today', 'week', 'month', 'year'], true)) {
            throw new InvalidArgumentException('The selected reporting period is invalid.');
        }
        $tz = new DateTimeZone(self::TIMEZONE);
        $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
        $selectedMonth = null;
        $selectedYear = null;

        if ($period === 'today') {
            $currentStart = $now->setTime(0, 0, 0);
            $currentEnd = $now;
            $priorStart = $currentStart->modify('-1 day');
            $priorEnd = $priorStart->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
            $comparisonLabel = 'vs same time yesterday';
            $periodLabel = 'Today · ' . $now->format('F j, Y');
        } elseif ($period === 'week') {
            $currentStart = $now->modify('monday this week')->setTime(0, 0, 0);
            $currentEnd = $now;
            $priorStart = $currentStart->modify('-7 days');
            $priorEnd = $priorStart->add($currentStart->diff($now));
            $comparisonLabel = 'vs same elapsed period last week';
            $periodLabel = 'This week · ' . $currentStart->format('M j') . '–' . $now->format('M j, Y');
        } elseif ($period === 'month') {
            $selectedMonth = self::positiveInteger($month ?? $now->format('n'), 'month');
            $selectedYear = self::yearValue($year ?? $now->format('Y'));
            if ($selectedMonth > 12) {
                throw new InvalidArgumentException('The selected month is invalid.');
            }
            $currentStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $selectedYear, $selectedMonth), $tz);
            $priorStart = $currentStart->modify('-1 month');
            if ($selectedYear === (int) $now->format('Y') && $selectedMonth === (int) $now->format('n')) {
                $currentEnd = $now;
                $priorDay = min((int) $now->format('j'), (int) $priorStart->format('t'));
                $priorEnd = $priorStart->setDate((int) $priorStart->format('Y'), (int) $priorStart->format('n'), $priorDay)
                    ->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
                $comparisonLabel = 'vs same elapsed period last month';
            } else {
                $currentEnd = $currentStart->modify('+1 month');
                $priorEnd = $currentStart;
                $comparisonLabel = 'vs previous month';
            }
            $periodLabel = $currentStart->format('F Y');
        } else {
            $selectedYear = self::yearValue($year ?? $now->format('Y'));
            $currentStart = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $selectedYear), $tz);
            $priorStart = $currentStart->modify('-1 year');
            if ($selectedYear === (int) $now->format('Y')) {
                $currentEnd = $now;
                $priorMonth = (int) $now->format('n');
                $priorMonthStart = $priorStart->setDate((int) $priorStart->format('Y'), $priorMonth, 1);
                $priorDay = min((int) $now->format('j'), (int) $priorMonthStart->format('t'));
                $priorEnd = $priorMonthStart->setDate((int) $priorStart->format('Y'), $priorMonth, $priorDay)
                    ->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
                $comparisonLabel = 'vs same elapsed period last year';
            } else {
                $currentEnd = $currentStart->modify('+1 year');
                $priorEnd = $currentStart;
                $comparisonLabel = 'vs previous year';
            }
            $periodLabel = $currentStart->format('Y');
        }

        return [
            'period' => $period,
            'timezone' => self::TIMEZONE,
            'current_start' => $currentStart,
            'current_end_exclusive' => $currentEnd,
            'prior_start' => $priorStart,
            'prior_end_exclusive' => $priorEnd,
            'comparison_label' => $comparisonLabel,
            'period_month' => $selectedMonth,
            'period_year' => $selectedYear,
            'period_label' => $periodLabel,
        ];
    }

    private static function positiveInteger(mixed $value, string $field): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('The selected ' . $field . ' is invalid.');
        }
        $text = (string) $value;
        if (!preg_match('/^\d{1,2}$/', $text) || (int) $text < 1) {
            throw new InvalidArgumentException('The selected ' . $field . ' is invalid.');
        }
        return (int) $text;
    }

    private static function yearValue(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('The selected year is invalid.');
        }
        $text = (string) $value;
        if (!preg_match('/^\d{4}$/', $text) || (int) $text < 1 || (int) $text > 9998) {
            throw new InvalidArgumentException('The selected year is invalid.');
        }
        return (int) $text;
    }

    public static function sql(DateTimeInterface $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }

    public static function bucket(string $period): array
    {
        return match ($period) {
            'today' => ["DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00:00')", 'hour', 'g A'],
            'year' => ["DATE_FORMAT(%s, '%%Y-%%m-01 00:00:00')", 'month', 'M'],
            default => ["DATE_FORMAT(%s, '%%Y-%%m-%%d 00:00:00')", 'day', 'M j'],
        };
    }

    public static function bucketSeries(string $period, DateTimeImmutable $start, DateTimeImmutable $endExclusive, array $values): array
    {
        [, $unit, $labelFormat] = self::bucket($period);
        $cursor = match ($unit) {
            'hour' => $start->setTime((int) $start->format('H'), 0, 0),
            'month' => $start->modify('first day of this month')->setTime(0, 0, 0),
            default => $start->setTime(0, 0, 0),
        };
        $labels = [];
        $series = [];
        while ($cursor < $endExclusive) {
            $key = $cursor->format('Y-m-d H:i:s');
            $labels[] = $cursor->format($labelFormat);
            $series[] = round((float) ($values[$key] ?? 0), 2);
            $cursor = $cursor->modify('+1 ' . $unit);
        }
        return ['labels' => $labels, 'values' => $series];
    }
}
