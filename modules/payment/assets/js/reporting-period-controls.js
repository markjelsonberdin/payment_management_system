(() => {
  const controls = () => Array.from(document.querySelectorAll('[data-payment-period-controls]'));
  const selection = root => ({
    period: root.querySelector('[data-period-value]')?.value || 'today',
    period_month: root.querySelector('[data-period-month]')?.value || '',
    period_year: root.querySelector('[data-period-year]')?.value || ''
  });

  function activate(root, period) {
    root.querySelector('[data-period-value]').value = period;
    root.querySelectorAll('[data-period-option]').forEach(button => {
      const active = button.dataset.periodOption === period;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    root.querySelector('[data-period-selection]').classList.toggle('d-none', !['month', 'year'].includes(period));
    root.querySelector('[data-period-month]').hidden = period !== 'month';
    root.querySelector('[data-period-year]').hidden = !['month', 'year'].includes(period);
    updateContext(root);
  }

  function updateContext(root) {
    const period = root.querySelector('[data-period-value]')?.value || 'today';
    const month = root.querySelector('[data-period-month]')?.selectedOptions?.[0]?.textContent || '';
    const year = root.querySelector('[data-period-year]')?.value || '';
    const context = root.querySelector('[data-period-context]');
    if (context) context.textContent = `Viewing: ${period === 'month' ? `${month} ${year}` : period === 'year' ? year : period.charAt(0).toUpperCase() + period.slice(1)}`;
  }

  function setAvailableYears(years) {
    const currentYear = Number(new Intl.DateTimeFormat('en', { timeZone: 'Asia/Manila', year: 'numeric' }).format(new Date()));
    const safeYears = Array.from(new Set([...(Array.isArray(years) ? years : []), currentYear]
      .map(value => Number(value)).filter(year => Number.isInteger(year) && year > 0 && year <= 9998)))
      .sort((a, b) => b - a);
    controls().forEach(root => {
      const select = root.querySelector('[data-period-year]');
    const selected = select.value || String(new Intl.DateTimeFormat('en', { timeZone: 'Asia/Manila', year: 'numeric' }).format(new Date()));
      if (selected && /^\d{4}$/.test(selected) && !safeYears.includes(Number(selected))) safeYears.push(Number(selected));
      safeYears.sort((a, b) => b - a);
      select.replaceChildren(...safeYears.map(year => {
        const option = document.createElement('option');
        option.value = String(year);
        option.textContent = String(year);
        return option;
      }));
      if (safeYears.includes(Number(selected))) select.value = selected;
      else if (safeYears.length) select.value = String(safeYears[0]);
    });
  }

  function initialize() {
    controls().forEach(root => {
      root.querySelectorAll('[data-period-option]').forEach(button => button.addEventListener('click', () => activate(root, button.dataset.periodOption)));
      root.querySelectorAll('[data-period-month],[data-period-year]').forEach(select => select.addEventListener('change', () => updateContext(root)));
      root.querySelector('[data-period-apply]')?.addEventListener('click', () => {
        const button = root.querySelector('[data-period-apply]');
        const label = root.querySelector('[data-period-apply-label]');
        if (button) button.disabled = true;
        if (label) label.textContent = 'Applying...';
        const form = root.closest('form');
        if (form) form.requestSubmit();
        else document.dispatchEvent(new CustomEvent('payment:period-apply', { detail: selection(root) }));
        window.setTimeout(() => { if (button) button.disabled = false; if (label) label.textContent = 'Apply Period'; }, 1200);
      });
      updateContext(root);
    });
  }

  window.PaymentReportingPeriodControls = { selection, setAvailableYears };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
})();
