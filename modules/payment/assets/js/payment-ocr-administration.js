(() => {
    'use strict';
    const root = document.getElementById('ocrAdminApp');
    if (!root) return;
    const byId = (id) => document.getElementById(id);
    const value = (input, fallback = 'Unavailable') => input === null || input === undefined || input === '' ? fallback : String(input);
    const setText = (id, input, fallback) => { const node = byId(id); if (node) node.textContent = value(input, fallback); };
    const state = (id, input) => { const node = byId(id); if (node) node.dataset.state = input; };
    const badge = (id, label, status = '') => { const node = byId(id); if (node) { node.textContent = label; node.className = `ocr-pill ${status}`; } };
    const titleCase = (input) => value(input).replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
    const monthLabel = (input) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(input || '')) return 'Current Asia/Manila billing month';
        const [year, month] = input.split('-').map(Number);
        return new Intl.DateTimeFormat('en-PH', { month: 'long', year: 'numeric', timeZone: 'Asia/Manila' }).format(new Date(Date.UTC(year, month - 1, 1)));
    };
    const timeLabel = (input) => {
        if (!input) return 'Not recorded';
        const date = new Date(input);
        return Number.isNaN(date.getTime()) ? input : new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(date);
    };
    function renderRows(id, rows, key) {
        const body = byId(id); if (!body) return; body.replaceChildren();
        if (!Array.isArray(rows) || rows.length === 0) { const row = body.insertRow(); const cell = row.insertCell(); cell.colSpan = 2; cell.className = 'text-muted'; cell.textContent = 'No recorded data this month.'; return; }
        rows.forEach((item) => { const row = body.insertRow(); row.insertCell().textContent = titleCase(item[key] || 'UNCLASSIFIED'); row.insertCell().textContent = value(item.total); });
    }
    function renderConfiguration(data) {
        const config = data.configuration || {};
        let label = 'Disabled', note = 'Receipt OCR processing is turned off.', cardState = '';
        if (config.enabled && (!config.project_configured || config.credential_status !== 'CONFIGURED')) { label = 'Not Configured'; note = 'Processing is enabled but configuration is incomplete.'; cardState = 'warning'; }
        else if (config.enabled) { label = 'Enabled'; note = 'Saved processing state; provider operation is not implied.'; cardState = 'success'; }
        setText('ocrSummaryStatus', label); setText('ocrSummaryStatusNote', note); state('ocrStatusCard', cardState);
        badge('ocrConfigurationBadge', config.project_configured ? 'Configured' : 'Incomplete', config.project_configured ? 'success' : 'warning');
        badge('ocrCredentialBadge', titleCase(config.credential_status), config.credential_status === 'CONFIGURED' ? 'success' : 'warning');
    }
    function renderDiagnostic(data) {
        const diagnostic = data.diagnostic || {}; let label = 'Not Checked', note = 'No persisted OAuth diagnostic.', cardState = '';
        if (diagnostic.authentication_state === 'VERIFIED') { label = 'Verified'; note = 'OAuth authentication was verified.'; cardState = 'success'; }
        else if (diagnostic.authentication_state === 'FAILED') { label = 'Failed'; note = 'The latest OAuth diagnostic failed.'; cardState = 'danger'; }
        else if (data.configuration?.credential_status !== 'CONFIGURED') { label = 'Not Configured'; note = 'Credential readiness is incomplete.'; cardState = 'warning'; }
        setText('ocrSummaryAuth', label); setText('ocrSummaryAuthNote', note); setText('ocrCredentialDiagnostic', label); state('ocrAuthCard', cardState);
        setText('ocrDiagnosticStatus', diagnostic.last_status ? titleCase(diagnostic.last_status) : 'Not Checked');
        setText('ocrDiagnosticTime', timeLabel(diagnostic.last_successful || diagnostic.last_failed));
        setText('ocrAuthentication', label); setText('ocrErrorCategory', diagnostic.error_category && diagnostic.error_category !== 'NONE' ? titleCase(diagnostic.error_category) : 'None recorded');
    }
    function renderUsage(data) {
        const usage = data.usage || {}, available = usage.available === true, recorded = available && usage.record_present === true;
        const used = recorded ? Number(usage.consumed || 0) + Number(usage.reserved || 0) : null;
        const limit = recorded ? Number(usage.configured_limit) : null;
        const percentage = limit > 0 ? Math.min(100, Math.max(0, used / limit * 100)) : 0;
        const period = monthLabel(usage.month);
        setText('ocrSummaryUsage', recorded ? `${used} units` : 'Unavailable'); setText('ocrSummaryMonth', period);
        setText('ocrSummaryRemaining', recorded ? `${usage.remaining} units` : 'Unavailable'); setText('ocrSummaryQuotaNote', 'Application ceiling, not Google Cloud allowance.');
        state('ocrUsageCard', recorded ? (percentage >= 90 ? 'warning' : 'success') : ''); state('ocrQuotaCard', recorded ? (Number(usage.remaining) === 0 ? 'danger' : 'success') : '');
        badge('ocrUsageAvailability', available ? (recorded ? 'Persisted Data' : 'No Monthly Record') : 'Unavailable', recorded ? 'success' : available ? '' : 'danger');
        setText('ocrUsagePeriod', `${period} · Asia/Manila`); setText('ocrQuotaFraction', recorded ? `${used} of ${limit} units used (${percentage.toFixed(1)}%)` : 'Usage unavailable');
        setText('ocrQuotaCeiling', recorded ? `Ceiling: ${limit} units` : 'Ceiling unavailable');
        const bar = byId('ocrQuotaProgress'); if (bar) bar.style.width = `${percentage}%`;
        [['ocrConsumed',recorded?usage.consumed:null],['ocrReserved',recorded?usage.reserved:null],['ocrRemaining',recorded?usage.remaining:null],['ocrProviderAttempts',available?usage.provider_attempts:null],['ocrSuccessful',recorded?usage.successful:null],['ocrFailed',recorded?usage.failed:null],['ocrCacheHits',available?usage.cache_hits:null],['ocrStale',available?data.stale_incomplete:null],['ocrConsumedLegend',recorded?usage.consumed:null],['ocrReservedLegend',recorded?usage.reserved:null],['ocrAvailableLegend',recorded?usage.remaining:null]].forEach(([id,item]) => setText(id,item));
    }
    async function refresh() {
        const button = byId('ocrRefreshStatus'); if (button) button.disabled = true;
        try { const response = await fetch(root.dataset.statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } }); const payload = await response.json(); if (!response.ok || !payload.ok) throw new Error(payload.message || 'OCR status is unavailable.'); const data = payload.data || {}; renderConfiguration(data); renderDiagnostic(data); renderUsage(data); renderRows('ocrLifecycleRows', data.lifecycle_states, 'lifecycle_state'); renderRows('ocrFailureRows', data.failure_categories, 'category'); }
        catch (error) { badge('ocrUsageAvailability', 'Unavailable', 'danger'); setText('ocrStatusMessage', error.message || 'OCR status is temporarily unavailable.'); }
        finally { if (button) button.disabled = false; }
    }
    const diagnosticForm = byId('ocrDiagnosticForm');
    async function runDiagnostic() {
        const button = byId('ocrRunDiagnostic'); if (!diagnosticForm || !button) return; button.disabled = true; setText('ocrStatusMessage', 'Running protected configuration and OAuth checks…');
        try { diagnosticForm.querySelector('[name="correlation_id"]').value = crypto.randomUUID(); const response = await fetch(root.dataset.diagnosticUrl, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new FormData(diagnosticForm) }); const payload = await response.json(); if (!response.ok || !payload.ok) throw new Error(payload.message || 'Authentication diagnostic failed.'); setText('ocrStatusMessage', payload.message); await refresh(); }
        catch (error) { setText('ocrStatusMessage', error.message || 'Authentication diagnostic is unavailable.'); }
        finally { button.disabled = false; }
    }
    byId('ocrRefreshStatus')?.addEventListener('click', refresh); byId('ocrRunDiagnostic')?.addEventListener('click', runDiagnostic);
    const configForm = byId('ocrConfigurationForm'); let modal = null;
    if (configForm) configForm.addEventListener('submit', (event) => {
        const currentEnabled = byId('ocrEnabled')?.checked ? '1' : '0', currentLimit = byId('monthlyLimit')?.value || '';
        const requiresConfirmation = currentEnabled !== configForm.dataset.originalEnabled || currentLimit !== configForm.dataset.originalLimit;
        if (!requiresConfirmation || configForm.dataset.confirmed === '1') return;
        event.preventDefault(); setText('ocrPreviousEnabled', configForm.dataset.originalEnabled === '1' ? 'Enabled' : 'Disabled'); setText('ocrNewEnabled', currentEnabled === '1' ? 'Enabled' : 'Disabled'); setText('ocrPreviousLimit', `${configForm.dataset.originalLimit} units`); setText('ocrNewLimit', `${currentLimit} units`);
        if (window.bootstrap?.Modal) { modal = window.bootstrap.Modal.getOrCreateInstance(byId('ocrConfirmModal')); modal.show(); }
        else if (window.confirm('This change affects OCR availability or its monthly application limit. Save the new configuration?')) { configForm.dataset.confirmed = '1'; configForm.requestSubmit(event.submitter); }
    });
    byId('ocrConfirmSave')?.addEventListener('click', () => { if (!configForm) return; configForm.dataset.confirmed = '1'; modal?.hide(); const marker = document.createElement('input'); marker.type = 'hidden'; marker.name = 'save_ocr_settings'; marker.value = '1'; configForm.appendChild(marker); configForm.requestSubmit(); });
    refresh();
})();
