<?php
declare(strict_types=1);

final class AccountingReportingPeriod
{
    public const TIMEZONE = 'Asia/Manila';

    public static function fromRequest(array $input, ?DateTimeImmutable $now = null): array
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
        $end = $now;
        $isCurrentRange = true;
        $rawPeriod = $input['period'] ?? 'today';
        if (!is_string($rawPeriod) && !is_int($rawPeriod)) {
            throw new InvalidArgumentException('The selected reporting period is invalid.');
        }
        $period = (string) $rawPeriod;

        if ($period === 'custom') {
            $startText = trim((string) ($input['start_date'] ?? ''));
            $endText = trim((string) ($input['end_date'] ?? ''));
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startText, $tz);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endText, $tz);
            if (!$start || !$end || $startText !== $start->format('Y-m-d') || $endText !== $end->format('Y-m-d') || $start > $end) {
                throw new InvalidArgumentException('Custom report dates are invalid.');
            }
            return [
                'period' => 'custom', 'timezone' => self::TIMEZONE,
                'start_at' => $start, 'end_exclusive' => $end->modify('+1 day'),
                'prior_start' => null, 'prior_end_exclusive' => null,
                'comparison_label' => null, 'period_month' => null, 'period_year' => null,
                'period_label' => $start->format('F j, Y') . ' – ' . $end->format('F j, Y'),
            ];
        }

        if (!in_array($period, ['today', 'week', 'month', 'year'], true)) {
            throw new InvalidArgumentException('The selected reporting period is invalid.');
        }
        $selectedMonth = null;
        $selectedYear = null;

        if ($period === 'today') {
            $start = $now->setTime(0, 0);
            $priorStart = $start->modify('-1 day');
            $priorEnd = $priorStart->add($start->diff($now));
            $comparison = 'vs same time yesterday';
            $label = 'Today · ' . $now->format('F j, Y');
        } elseif ($period === 'week') {
            $start = $now->modify('monday this week')->setTime(0, 0);
            $priorStart = $start->modify('-7 days');
            $priorEnd = $priorStart->add($start->diff($now));
            $comparison = 'vs same elapsed period last week';
            $label = 'This week · ' . $start->format('M j') . '–' . $now->format('M j, Y');
        } elseif ($period === 'month') {
            $selectedMonth = self::positiveInteger($input['period_month'] ?? $now->format('n'), 'month');
            $selectedYear = self::yearValue($input['period_year'] ?? $now->format('Y'));
            if ($selectedMonth > 12) {
                throw new InvalidArgumentException('The selected month is invalid.');
            }
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $selectedYear, $selectedMonth), $tz);
            $priorStart = $start->modify('-1 month');
            if ($selectedYear === (int) $now->format('Y') && $selectedMonth === (int) $now->format('n')) {
                $end = $now;
                $day = min((int) $now->format('j'), (int) $priorStart->format('t'));
                $priorEnd = $priorStart->setDate((int) $priorStart->format('Y'), (int) $priorStart->format('n'), $day)
                    ->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
                $comparison = 'vs same elapsed period last month';
            } else {
                $end = $start->modify('+1 month');
                $priorEnd = $start;
                $isCurrentRange = false;
                $comparison = 'vs previous month';
            }
            $label = $start->format('F Y');
        } else {
            $selectedYear = self::yearValue($input['period_year'] ?? $now->format('Y'));
            $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $selectedYear), $tz);
            $priorStart = $start->modify('-1 year');
            if ($selectedYear === (int) $now->format('Y')) {
                $end = $now;
                $month = (int) $now->format('n');
                $day = min((int) $now->format('j'), cal_days_in_month(CAL_GREGORIAN, $month, (int) $priorStart->format('Y')));
                $priorEnd = $priorStart->setDate((int) $priorStart->format('Y'), $month, $day)
                    ->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
                $comparison = 'vs same elapsed period last year';
            } else {
                $end = $start->modify('+1 year');
                $priorEnd = $start;
                $isCurrentRange = false;
                $comparison = 'vs previous year';
            }
            $label = $start->format('Y');
        }

        return [
            'period' => $period, 'timezone' => self::TIMEZONE,
            'start_at' => $start, 'end_exclusive' => $end ?? $now,
            'prior_start' => $priorStart, 'prior_end_exclusive' => $priorEnd,
            'comparison_label' => $comparison,
            'period_month' => $selectedMonth, 'period_year' => $selectedYear,
            'is_current_range' => $isCurrentRange,
            'period_label' => $label,
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

    public static function sql(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }
}
