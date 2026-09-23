<?php
declare(strict_types=1);

final class CashierReportingPeriod
{
    public const TIMEZONE = 'Asia/Manila';

    public static function resolve(string $period, ?DateTimeImmutable $now = null): array
    {
        $period = in_array($period, ['today', 'week', 'month', 'year'], true) ? $period : 'today';
        $tz = new DateTimeZone(self::TIMEZONE);
        $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);

        if ($period === 'today') {
            $currentStart = $now->setTime(0, 0, 0);
            $priorStart = $currentStart->modify('-1 day');
            $priorEnd = $priorStart->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
            $comparisonLabel = 'vs same time yesterday';
        } elseif ($period === 'week') {
            $currentStart = $now->modify('monday this week')->setTime(0, 0, 0);
            $priorStart = $currentStart->modify('-7 days');
            $priorEnd = $priorStart->add($currentStart->diff($now));
            $comparisonLabel = 'vs same elapsed period last week';
        } elseif ($period === 'month') {
            $currentStart = $now->modify('first day of this month')->setTime(0, 0, 0);
            $priorStart = $currentStart->modify('first day of previous month');
            $priorDay = min((int) $now->format('j'), (int) $priorStart->format('t'));
            $priorEnd = $priorStart->setDate((int) $priorStart->format('Y'), (int) $priorStart->format('n'), $priorDay)
                ->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
            $comparisonLabel = 'vs same elapsed period last month';
        } else {
            $currentStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
            $priorStart = $currentStart->modify('-1 year');
            $priorYear = (int) $priorStart->format('Y');
            $month = (int) $now->format('n');
            $priorMonthStart = $priorStart->setDate($priorYear, $month, 1);
            $day = min((int) $now->format('j'), (int) $priorMonthStart->format('t'));
            $priorEnd = $priorMonthStart->setDate($priorYear, $month, $day)
                ->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
            $comparisonLabel = 'vs same elapsed period last year';
        }

        return ['period'=>$period, 'timezone'=>self::TIMEZONE, 'current_start'=>$currentStart, 'current_end_exclusive'=>$now,
            'prior_start'=>$priorStart, 'prior_end_exclusive'=>$priorEnd, 'comparison_label'=>$comparisonLabel];
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
            'hour' => $start->setTime((int)$start->format('H'), 0, 0),
            'month' => $start->modify('first day of this month')->setTime(0, 0, 0),
            default => $start->setTime(0, 0, 0),
        };
        $labels=[]; $series=[];
        while ($cursor < $endExclusive) {
            $key=$cursor->format('Y-m-d H:i:s');
            $labels[]=$cursor->format($labelFormat);
            $series[]=round((float)($values[$key] ?? 0), 2);
            $cursor=$cursor->modify('+1 '.$unit);
        }
        return ['labels'=>$labels, 'values'=>$series];
    }
}
