<?php
$reportingControlPeriod = in_array(($reportingControlPeriod ?? 'today'), ['today', 'week', 'month', 'year'], true) ? $reportingControlPeriod : 'today';
$reportingControlNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$reportingControlMonth = (int) ($reportingControlMonth ?? $reportingControlNow->format('n'));
$reportingControlYear = (int) ($reportingControlYear ?? $reportingControlNow->format('Y'));
if ($reportingControlMonth < 1 || $reportingControlMonth > 12) $reportingControlMonth = (int) $reportingControlNow->format('n');
if ($reportingControlYear < 1 || $reportingControlYear > 9998) $reportingControlYear = (int) $reportingControlNow->format('Y');
$reportingControlYears = array_values(array_unique(array_filter(array_map('intval', $reportingControlYears ?? []), static fn (int $year): bool => $year > 0 && $year <= 9998)));
$reportingCurrentYear = (int) $reportingControlNow->format('Y');
if (!in_array($reportingCurrentYear, $reportingControlYears, true)) $reportingControlYears[] = $reportingCurrentYear;
$reportingRequestedYear = filter_var($reportingControlYear, FILTER_VALIDATE_INT);
if ($reportingRequestedYear && $reportingRequestedYear > 0 && $reportingRequestedYear <= 9998 && !in_array($reportingRequestedYear, $reportingControlYears, true)) $reportingControlYears[] = $reportingRequestedYear;
if (!in_array($reportingControlYear, $reportingControlYears, true)) $reportingControlYear = $reportingCurrentYear;
rsort($reportingControlYears, SORT_NUMERIC);
?>
<div class="payment-period-controls" data-payment-period-controls>
  <input type="hidden" name="period" value="<?= htmlspecialchars($reportingControlPeriod, ENT_QUOTES, 'UTF-8') ?>" data-period-value>
  <div class="btn-group payment-period-tabs" role="group" aria-label="Reporting period">
    <?php foreach (['today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $value => $label): ?>
      <button type="button" class="btn btn-outline-primary<?= $reportingControlPeriod === $value ? ' active' : '' ?>" data-period-option="<?= $value ?>" aria-pressed="<?= $reportingControlPeriod === $value ? 'true' : 'false' ?>"><?= $label ?></button>
    <?php endforeach; ?>
  </div>
  <div class="payment-period-selection<?= in_array($reportingControlPeriod, ['month', 'year'], true) ? '' : ' d-none' ?>" data-period-selection>
    <span class="payment-period-viewing">Viewing</span>
    <label class="visually-hidden" for="paymentPeriodMonth">Month</label>
    <select id="paymentPeriodMonth" name="period_month" class="form-select form-select-sm" data-period-month<?= $reportingControlPeriod === 'month' ? '' : ' hidden' ?>>
      <?php for ($month = 1; $month <= 12; $month++): $monthLabel = (new DateTimeImmutable(sprintf('2000-%02d-01', $month)))->format('F'); ?>
        <option value="<?= $month ?>"<?= $reportingControlMonth === $month ? ' selected' : '' ?>><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></option>
      <?php endfor; ?>
    </select>
    <label class="visually-hidden" for="paymentPeriodYear">Year</label>
    <select id="paymentPeriodYear" name="period_year" class="form-select form-select-sm" data-period-year<?= in_array($reportingControlPeriod, ['month', 'year'], true) ? '' : ' hidden' ?>>
      <?php foreach ($reportingControlYears as $year): ?>
        <option value="<?= $year ?>"<?= $reportingControlYear === $year ? ' selected' : '' ?>><?= $year ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="button" class="btn btn-primary btn-sm payment-period-apply" data-period-apply>
    <i class="ti ti-filter me-1" aria-hidden="true"></i>Apply
  </button>
</div>
