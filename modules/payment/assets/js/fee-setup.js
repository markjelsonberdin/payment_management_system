(() => {
    'use strict';

    const app = document.getElementById('feeSetupApp');
    if (!app) return;

    const byId = id => document.getElementById(id);
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[char]);
    const peso = value => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value || 0));
    const showModal = id => bootstrap.Modal.getOrCreateInstance(byId(id)).show();
    const hideModal = id => bootstrap.Modal.getOrCreateInstance(byId(id)).hide();
    const bootstrapData = window.FEE_SETUP_BOOTSTRAP || {};
    const state = {
        api: app.dataset.api,
        csrf: bootstrapData.csrf_token || '',
        taxonomy: Array.isArray(bootstrapData.taxonomy) ? bootstrapData.taxonomy : [],
        catalog: Array.isArray(bootstrapData.catalog) ? bootstrapData.catalog : [],
        legacy: Array.isArray(bootstrapData.legacy) ? bootstrapData.legacy : [],
        details: new Map(), fee: null, legacyFee: null, confirm: null
    };
    const friendlyErrors = {
        FEE_CODE_EXISTS: 'This fee code is already in use and remains permanently reserved.',
        FEE_CODE_IMMUTABLE: 'Fee code cannot be changed after creation.',
        FEE_TYPE_LOCKED: 'Fee type can no longer be changed because versions already exist.',
        IDENTITY_LOCKED: 'This fee identity is locked because a version has already been activated.',
        IDENTITY_ARCHIVED: 'Archived fee identities are read-only.',
        IDENTITY_HAS_OPEN_VERSIONS: 'Archive all Draft and Active versions before archiving this fee.',
        VERSION_NOT_DRAFT: 'Only Draft versions may be edited.',
        ACTIVE_VERSION_EXISTS: 'An Active version already exists for this academic year and semester.',
        VERSION_TERM_EXISTS: 'This fee already has a version for the selected academic year and semester.',
        INVALID_APPLICABILITY: 'Choose either All or one specific value for both program and year level.',
        DUPLICATE_APPLICABILITY: 'That program and year-level scope was already added.',
        LEGACY_FEE_ALREADY_CLASSIFIED: 'This legacy fee has already been classified. The list will be refreshed.',
        CSRF_INVALID: 'Your security token expired. Refresh the page and try again.'
    };

    async function api(action, options = {}) {
        const method = options.method || 'GET';
        let url = `${state.api}?action=${encodeURIComponent(action)}`;
        Object.entries(options.ids || {}).forEach(([key, value]) => { url += `&${key}=${encodeURIComponent(value)}`; });
        const request = { method, headers: { Accept: 'application/json' } };
        if (method === 'POST') {
            request.headers['Content-Type'] = 'application/json';
            request.headers['X-CSRF-Token'] = state.csrf;
            request.body = JSON.stringify({ action, ...(options.ids || {}), data: options.data || {} });
        }
        const response = await fetch(url, request);
        let payload;
        try { payload = await response.json(); } catch { throw new Error('Fee Setup returned an unreadable response.'); }
        if (payload.csrf_token) state.csrf = payload.csrf_token;
        if (!response.ok || !payload.ok) {
            const error = new Error(friendlyErrors[payload.error] || payload.message || 'The request could not be completed.');
            error.code = payload.error;
            throw error;
        }
        return payload.data;
    }

    function alertUser(message, type = 'success') {
        byId('feeAlert').className = `alert alert-${type} alert-dismissible fade show border-0 shadow-sm rounded-3`;
        byId('feeAlertText').innerHTML = `<i class="ti ti-${type === 'success' ? 'circle-check' : 'alert-triangle'} me-2"></i>${escapeHtml(message)}`;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function setBusy(button, busy, label = 'Working...') {
        if (busy) {
            button.dataset.original = button.innerHTML;
            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${label}`;
        } else {
            button.disabled = false;
            button.innerHTML = button.dataset.original || button.innerHTML;
        }
    }

    function groupByCode(code) { return state.taxonomy.find(group => group.group_code === code); }
    function typesFor(groupCode) { return groupByCode(groupCode)?.types || []; }

    function populateTaxonomy(groupSelect, typeSelect, selectedGroup = '', selectedType = 0) {
        groupSelect.innerHTML = '<option value="">Select fee group</option>' + state.taxonomy.map(group => `<option value="${escapeHtml(group.group_code)}">${escapeHtml(group.group_name)}</option>`).join('');
        groupSelect.value = selectedGroup;
        const refresh = () => {
            const types = typesFor(groupSelect.value);
            typeSelect.innerHTML = '<option value="">Select fee type</option>' + types.map(type => `<option value="${type.fee_type_id}">${escapeHtml(type.type_name)}</option>`).join('');
            if (selectedType) typeSelect.value = String(selectedType);
        };
        groupSelect.onchange = () => { selectedType = 0; refresh(); };
        refresh();
    }

    function activeVersion(fee) { return fee.versions.find(version => version.effective_status === 'Active') || null; }
    function displayVersion(fee) { return activeVersion(fee) || fee.versions.find(version => version.effective_status === 'Draft') || fee.versions[0] || null; }

    function renderAccordion() {
        const search = byId('feeSearch').value.trim().toLowerCase();
        const typeRows = [];
        state.taxonomy.forEach(group => group.types.forEach(type => {
            const fees = state.catalog.filter(fee => Number(fee.fee_type_id) === Number(type.fee_type_id) &&
                `${fee.fee_code} ${fee.fee_name} ${type.type_name}`.toLowerCase().includes(search));
            if (search && fees.length === 0) return;
            typeRows.push({ group, type, fees });
        }));

        let previousGroup = '';
        byId('feesAccordion').innerHTML = typeRows.map((entry, index) => {
            const groupHeading = entry.group.group_code !== previousGroup ? `<div class="text-uppercase fw-bold text-secondary small mt-4 mb-2 ps-1">${escapeHtml(entry.group.group_name)}</div>` : '';
            previousGroup = entry.group.group_code;
            return `${groupHeading}<div class="accordion-item border-0 mb-3 shadow-sm rounded-4 overflow-hidden fee-accordion-item">
                <h2 class="accordion-header" id="feeHeading${index}">
                    <button class="accordion-button ${index === 0 ? '' : 'collapsed'} bg-white fw-bold p-3" type="button" data-bs-toggle="collapse" data-bs-target="#feeCollapse${index}">
                        <div class="flex-grow-1"><span class="fee-group-label text-primary">${escapeHtml(entry.group.group_name)}</span><div class="text-dark fs-6"><i class="ti ti-stack-2 text-primary me-2 opacity-75"></i>${escapeHtml(entry.type.type_name)}</div><div class="text-muted fw-normal fee-group-meta mt-1">${entry.fees.length} Managed Items</div></div>
                    </button>
                </h2>
                <div id="feeCollapse${index}" class="accordion-collapse collapse ${index === 0 ? 'show' : ''}" data-bs-parent="#feesAccordion">
                    <div class="accordion-body p-0 bg-light border-top"><div class="payment-operational-table border-0 rounded-0 shadow-none"><div class="table-responsive"><table class="table align-middle mb-0 fee-items-table">
                        <thead><tr><th class="ps-4">FEE NAME</th><th class="text-center">CURRENT VERSION</th><th class="text-center">STATUS</th><th class="text-end">AMOUNT</th><th class="text-center pe-4">ACTIONS</th></tr></thead>
                        <tbody>${renderFeeRows(entry.fees)}</tbody>
                    </table></div>${entry.fees.length ? '' : '<div class="fee-type-empty">No managed fees configured for this fee type.</div>'}</div>
                </div>
            </div>`;
        }).join('');
        byId('feesEmpty').classList.toggle('d-none', typeRows.length > 0);
    }

    function renderFeeRows(fees) {
        return fees.map(summary => {
            const hasActive = summary.active_version_no !== null;
            const status = summary.identity_status === 'Archived' ? 'Archived' : (hasActive ? 'Active' : summary.latest_draft_version_no !== null ? 'Draft / No Active Version' : 'No Active Version');
            const badge = status === 'Active' ? 'success' : status.startsWith('Draft') ? 'warning' : 'secondary';
            return `<tr>
                <td class="ps-4"><div class="payment-table-primary">${escapeHtml(summary.fee_name)}</div><div class="fee-code">${escapeHtml(summary.fee_code)}</div></td>
                <td class="text-center">${hasActive ? `v${summary.active_version_no}<div class="payment-table-secondary">${escapeHtml(summary.active_academic_year)} / ${escapeHtml(summary.active_semester)}</div>` : summary.latest_draft_version_no !== null ? `v${summary.latest_draft_version_no}<div class="payment-table-secondary text-warning">Draft / No Active Version</div>` : '—<div class="payment-table-secondary text-warning">No Active Version</div>'}</td>
                <td class="text-center"><span class="badge text-bg-${badge} fee-state-badge">${escapeHtml(status)}</span></td>
                <td class="payment-table-money ${hasActive ? 'text-success' : 'text-muted'}">${hasActive ? peso(summary.active_amount) : '—'}</td>
                <td class="text-center pe-4"><div class="payment-table-actions w-100 justify-content-center"><button class="btn btn-sm btn-light text-primary shadow-sm" data-action="editIdentity" data-id="${summary.fee_id}" ${summary.identity_locked ? 'disabled' : ''} title="${summary.identity_locked ? 'Identity locked after activation' : 'Edit identity'}" aria-label="${summary.identity_locked ? 'Identity locked after activation' : 'Edit fee identity'}"><i class="ti ti-edit"></i></button><button class="btn btn-sm btn-light text-primary shadow-sm" data-action="versions" data-id="${summary.fee_id}" title="Manage versions" aria-label="Manage fee versions"><i class="ti ti-versions"></i></button><button class="btn btn-sm btn-light text-danger shadow-sm" data-action="archiveIdentity" data-id="${summary.fee_id}" title="Archive fee" aria-label="Archive fee"><i class="fas fa-box-archive"></i></button></div></td>
            </tr>`;
        }).join('');
    }

    function renderLegacy() {
        const search = byId('legacySearch').value.trim().toLowerCase();
        const rows = state.legacy.filter(fee => `${fee.fee_name} ${fee.category_name || ''}`.toLowerCase().includes(search));
        byId('legacyRows').innerHTML = rows.map(fee => `<tr><td class="ps-4"><div class="payment-table-primary">${escapeHtml(fee.fee_name)}</div><div class="payment-table-secondary">Fee #${fee.fee_id}</div></td><td><div class="payment-table-primary">${escapeHtml(fee.category_name || 'Uncategorized')}</div></td><td class="payment-table-money text-success">${peso(fee.default_amount)}</td><td><span class="badge bg-light text-dark border">${escapeHtml(fee.status)}</span></td><td class="pe-4 text-end"><div class="payment-table-actions"><button class="btn btn-sm btn-primary shadow-sm px-3 w-auto" style="min-width:auto" data-action="classify" data-id="${fee.fee_id}">Classify</button></div></td></tr>`).join('');
        byId('legacyEmpty').classList.toggle('d-none', rows.length > 0);
        const legacyCount = byId('legacyCount');
        if (legacyCount) legacyCount.textContent = state.legacy.length;
    }

    async function loadLegacyFees() {
        byId('legacyLoading').classList.remove('d-none');
        byId('legacyResults').classList.add('d-none');
        byId('legacyError').classList.add('d-none');
        try {
            state.legacy = await api('legacy_fees');
            renderLegacy();
            byId('legacyResults').classList.remove('d-none');
        } catch (error) {
            byId('legacyErrorText').textContent = error.message;
            byId('legacyError').classList.remove('d-none');
        } finally {
            byId('legacyLoading').classList.add('d-none');
        }
    }

    async function loadArchives() {
        byId('archivesLoading').classList.remove('d-none');
        byId('archivesContent').classList.add('d-none');
        byId('archivesError').classList.add('d-none');
        try {
            const data = await api('archives');
            byId('archivedIdentityRows').innerHTML = data.identities.map(item => `<tr><td class="ps-4"><div class="payment-table-primary">${escapeHtml(item.fee_name)}</div><div class="fee-code mt-1">${escapeHtml(item.fee_code)}</div></td><td><div class="payment-table-primary">${escapeHtml(item.group_name)} / ${escapeHtml(item.type_name)}</div></td><td><div class="payment-table-primary">${item.version_count}</div></td><td><div class="payment-table-secondary">${escapeHtml(item.archived_at || '—')}</div></td></tr>`).join('');
            byId('archivedVersionRows').innerHTML = data.versions.map(item => `<tr><td class="ps-4"><div class="payment-table-primary">${escapeHtml(item.fee_name)}</div><div class="fee-code mt-1">${escapeHtml(item.fee_code)}</div></td><td><div class="payment-table-primary">v${item.version_no}</div><div class="payment-table-secondary">${escapeHtml(item.academic_year)} / ${escapeHtml(item.semester)}</div></td><td><div class="payment-table-primary">${peso(item.amount)} · ${escapeHtml(item.behavior)} · ${item.is_required ? 'Required' : 'Not required'}</div><div class="payment-table-secondary">${item.applicability.map(scopeLabel).map(escapeHtml).join(' • ') || 'No applicability recorded'}</div></td><td><div class="payment-table-secondary">${escapeHtml(item.archived_at || '—')}</div></td></tr>`).join('');
            byId('archivesEmpty').classList.toggle('d-none', data.identities.length + data.versions.length > 0);
            byId('archivesContent').classList.remove('d-none');
        } catch (error) {
            byId('archivesErrorText').textContent = error.message;
            byId('archivesError').classList.remove('d-none');
        } finally {
            byId('archivesLoading').classList.add('d-none');
        }
    }

    async function refreshCatalog() {
        try {
            state.catalog = await api('catalog');
            state.details.clear();
            renderAccordion();
        } catch (error) {
            alertUser(error.message, 'danger');
        }
    }

    async function loadManagedFee(feeId) {
        const id = Number(feeId);
        if (!state.details.has(id)) state.details.set(id, await api('fee', { ids: { fee_id: id } }));
        return state.details.get(id);
    }

    function openIdentity(fee = null) {
        byId('identityForm').reset();
        byId('identityId').value = fee?.fee_id || '';
        byId('identityTitle').textContent = fee ? 'Edit Fee Configuration' : 'Add New Fee';
        byId('identityCode').value = fee?.fee_code || '';
        byId('identityCode').disabled = Boolean(fee);
        const identityLocked = Boolean(fee?.identity_locked);
        byId('identityName').disabled = identityLocked;
        byId('identitySave').disabled = identityLocked;
        byId('identityName').value = fee?.fee_name || '';
        setFieldError(byId('identityName'), '');
        byId('identityDescriptionWrap').classList.toggle('d-none', Boolean(fee));
        populateTaxonomy(byId('identityGroup'), byId('identityType'), fee?.group_code || '', fee?.fee_type_id || 0);
        const classificationLocked = identityLocked || Boolean(fee?.versions.length);
        byId('identityGroup').disabled = classificationLocked; byId('identityType').disabled = classificationLocked;
        byId('typeLockedHelp').textContent = identityLocked ? 'Fee identity is permanently locked after first activation.' : 'Locked because versions exist.';
        byId('typeLockedHelp').classList.toggle('d-none', !classificationLocked);
        showModal('identityModal');
    }

    function openIdentityLoading(summary) {
        byId('identityForm').reset();
        byId('identityId').value = summary.fee_id;
        byId('identityTitle').textContent = 'Edit Fee Configuration';
        byId('identityCode').value = summary.fee_code;
        byId('identityName').value = 'Loading fee details…';
        byId('identityDescriptionWrap').classList.add('d-none');
        [byId('identityCode'), byId('identityName'), byId('identityGroup'), byId('identityType'), byId('identitySave')].forEach(field => { field.disabled = true; });
        showModal('identityModal');
    }

    function scopeLabel(scope) {
        return `${Number(scope.applies_to_all_courses) ? 'All Programs' : scope.course} / ${Number(scope.applies_to_all_year_levels) ? 'All Year Levels' : scope.year_level}`;
    }

    function addScope(container, scope = {}) {
        const row = document.createElement('div');
        row.className = 'fee-scope-row row g-2 align-items-end';
        row.innerHTML = `<div class="col-sm-3"><label class="form-label small">Program Scope</label><select class="form-select course-mode"><option value="all">All Programs</option><option value="specific" disabled>Specific Program — Registrar catalog required</option></select></div><div class="col-sm-3 course-field d-none"><label class="form-label small">Program</label><input class="form-control course-value" readonly></div><div class="col-sm-3"><label class="form-label small">Year Scope</label><select class="form-select year-mode"><option value="specific">Specific Year Level</option><option value="all">All Year Levels</option></select></div><div class="col-sm-2 year-field"><label class="form-label small">Year Level</label><select class="form-select year-value"><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div><div class="col-sm-1"><button class="btn btn-light border text-danger w-100 remove-scope" type="button"><i class="ti ti-trash"></i></button></div><div class="col-12 program-gap small text-warning"><i class="ti ti-info-circle me-1"></i>Specific Program is unavailable until an authoritative Registrar program catalog is exposed.</div>`;
        container.appendChild(row);
        const courseMode = row.querySelector('.course-mode'), yearMode = row.querySelector('.year-mode');
        courseMode.value = Number(scope.applies_to_all_courses) || !scope.course ? 'all' : 'specific';
        yearMode.value = Number(scope.applies_to_all_year_levels) ? 'all' : 'specific';
        row.querySelector('.course-value').value = scope.course || '';
        row.querySelector('.year-value').value = ['1', '2', '3', '4'].includes(String(scope.year_level)) ? String(scope.year_level) : '1';
        const toggle = () => {
            row.querySelector('.course-field').classList.toggle('d-none', courseMode.value === 'all');
            row.querySelector('.program-gap').classList.toggle('d-none', courseMode.value === 'specific');
            row.querySelector('.year-field').classList.toggle('d-none', yearMode.value === 'all');
        };
        courseMode.onchange = toggle; yearMode.onchange = toggle;
        row.querySelector('.remove-scope').onclick = () => row.remove(); toggle();
    }

    function readScopes(container) {
        return [...container.querySelectorAll('.fee-scope-row')].map(row => ({
            applies_to_all_courses: row.querySelector('.course-mode').value === 'all' ? 1 : 0,
            course: row.querySelector('.course-mode').value === 'all' ? null : row.querySelector('.course-value').value.trim(),
            applies_to_all_year_levels: row.querySelector('.year-mode').value === 'all' ? 1 : 0,
            year_level: row.querySelector('.year-mode').value === 'all' ? null : row.querySelector('.year-value').value.trim()
        }));
    }

    function versionPayload(prefix) {
        return { academic_year: byId(`${prefix}Year`).value.trim(), semester: byId(`${prefix}Term`).value, amount: byId(`${prefix}Amount`).value, behavior: byId(`${prefix}Behavior`).value, is_required: byId(`${prefix}Required`).value, description: byId(`${prefix}Description`).value.trim() || null };
    }

    function openVersions(fee) {
        state.fee = fee;
        byId('versionsTitle').textContent = fee.fee_name;
        byId('versionsCode').textContent = `${fee.fee_code} · ${fee.type_name}`;
        byId('newVersionActions').classList.toggle('d-none', fee.identity_status === 'Archived');
        byId('versionsList').innerHTML = fee.versions.length ? fee.versions.map(version => `<div class="fee-version-item" data-status="${version.effective_status}"><div class="d-flex flex-wrap justify-content-between gap-3"><div><div class="fw-bold">Version ${version.version_no} <span class="badge text-bg-${version.effective_status === 'Active' ? 'success' : version.effective_status === 'Draft' ? 'warning' : 'secondary'}">${escapeHtml(version.effective_status)}</span></div><div>${escapeHtml(version.academic_year)} / ${escapeHtml(version.semester)} &bull; <strong>${peso(version.amount)}</strong> &bull; ${escapeHtml(version.behavior)}</div><small class="text-muted">${version.applicability.map(scopeLabel).map(escapeHtml).join(' • ')}</small></div><div class="payment-table-actions">${version.effective_status === 'Draft' ? `<button class="btn btn-sm btn-light border text-primary" data-version-action="edit" data-id="${version.fee_version_id}">Edit</button> <button class="btn btn-sm btn-success" data-version-action="activate" data-id="${version.fee_version_id}">Activate</button> <button class="btn btn-sm btn-light border text-danger" data-version-action="archive" data-id="${version.fee_version_id}">Archive</button>` : version.effective_status === 'Active' ? `<button class="btn btn-sm btn-light border text-danger" data-version-action="archive" data-id="${version.fee_version_id}">Archive</button>` : '<span class="small text-muted">Read only</span>'}</div></div></div>`).join('') : '<div class="text-center text-muted py-4">No versions yet. Create the first Draft version.</div>';
        showModal('versionsModal');
    }

    function openVersionsLoading(summary) {
        byId('versionsTitle').textContent = summary.fee_name;
        byId('versionsCode').textContent = `${summary.fee_code} · Loading version details…`;
        byId('newVersionActions').classList.add('d-none');
        byId('versionsList').innerHTML = '<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Fetching fee versions…</div>';
        showModal('versionsModal');
    }

    function openVersionEditor(fee, version = null, copy = null) {
        byId('versionForm').reset();
        const source = version || copy;
        byId('versionFeeId').value = fee.fee_id; byId('versionId').value = version?.fee_version_id || '';
        byId('versionTitle').textContent = version ? 'Edit Draft Version' : 'Create Draft Version';
        byId('versionFeeLabel').textContent = `${fee.fee_code} · ${fee.fee_name}`;
        byId('versionYear').value = source?.academic_year || ''; byId('versionTerm').value = source?.semester || '1st';
        byId('versionAmount').value = source?.amount || ''; byId('versionBehavior').value = source?.behavior || 'Standard';
        byId('versionRequired').value = String(source?.is_required ?? 1); byId('versionDescription').value = source?.description || '';
        byId('versionScopes').innerHTML = ''; (source?.applicability || [{}]).forEach(scope => addScope(byId('versionScopes'), scope));
        validateVersionTermConflict();
        hideModal('versionsModal'); showModal('versionEditorModal');
    }

    function validateVersionTermConflict() {
        if (!state.fee) return true;
        const versionId = Number(byId('versionId').value);
        const year = byId('versionYear').value.trim();
        const semester = byId('versionTerm').value;
        const conflict = state.fee.versions.find(item => Number(item.fee_version_id) !== versionId && item.academic_year === year && item.semester === semester);
        const message = conflict ? `Version ${conflict.version_no} (${conflict.effective_status}) already uses this academic year and semester.` : '';
        setFieldError(byId('versionYear'), message);
        byId('versionSave').disabled = Boolean(conflict);
        return !conflict;
    }

    function confirmAction(title, text, action, successStyle = false, refreshFeeId = null) {
        byId('confirmTitle').textContent = title; byId('confirmText').innerHTML = text;
        byId('confirmAction').className = `btn btn-${successStyle ? 'success' : 'warning'} ${successStyle ? '' : 'text-dark'} fw-bold shadow-sm w-50`;
        state.confirm = { action, refreshFeeId }; showModal('confirmModal');
    }

    function openClassification(fee) {
        state.legacyFee = fee; byId('classificationForm').reset(); byId('legacyFeeId').value = fee.fee_id;
        byId('legacyReference').innerHTML = [['Fee ID', fee.fee_id], ['Fee Name', fee.fee_name], ['Legacy Category', fee.category_name || 'Uncategorized'], ['Legacy Amount', peso(fee.default_amount)], ['Legacy Required', Number(fee.is_required) ? 'Yes' : 'No'], ['Legacy Status', fee.status]].map(item => `<div class="col-sm-4"><small class="text-muted">${item[0]}</small><div class="fw-semibold">${escapeHtml(item[1])}</div></div>`).join('');
        populateTaxonomy(byId('legacyGroup'), byId('legacyType'));
        byId('legacyScopes').innerHTML = ''; addScope(byId('legacyScopes')); showClassificationPreview(false);
        hideModal('legacyModal'); showModal('classificationModal');
    }

    function classificationPayload() {
        return { fee_code: byId('legacyCode').value.toUpperCase().trim(), fee_type_id: Number(byId('legacyType').value), identity_status: 'Active', version: versionPayload('legacy'), applicability: readScopes(byId('legacyScopes')) };
    }

    function showClassificationPreview(show) {
        byId('classificationEditor').classList.toggle('d-none', show); byId('classificationPreview').classList.toggle('d-none', !show);
        byId('previewBack').classList.toggle('d-none', !show); byId('previewClassification').classList.toggle('d-none', show); byId('commitClassification').classList.toggle('d-none', !show);
    }

    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-action],[data-version-action]'); if (!button) return;
        if (button.dataset.action) {
            const id = Number(button.dataset.id);
            try {
                const summary = state.catalog.find(item => Number(item.fee_id) === id);
                if (button.dataset.action === 'editIdentity') {
                    openIdentityLoading(summary);
                    const fee = await loadManagedFee(id);
                    if (byId('identityModal').classList.contains('show')) openIdentity(fee);
                }
                if (button.dataset.action === 'versions') {
                    openVersionsLoading(summary);
                    const fee = await loadManagedFee(id);
                    if (byId('versionsModal').classList.contains('show')) openVersions(fee);
                }
            } catch (error) { alertUser(error.message, 'danger'); }
            if (button.dataset.action === 'archiveIdentity') confirmAction('Archive Fee?', `<p>This fee can no longer receive new versions.</p><p class="mb-0">Existing versions and history will remain.</p>`, () => api('archive_identity', { method: 'POST', ids: { fee_id: id } }));
            if (button.dataset.action === 'classify') openClassification(state.legacy.find(item => Number(item.fee_id) === id));
        } else {
            const version = state.fee.versions.find(item => Number(item.fee_version_id) === Number(button.dataset.id));
            if (button.dataset.versionAction === 'edit') openVersionEditor(state.fee, version);
            if (button.dataset.versionAction === 'activate') confirmAction('Activate Fee Version?', `<p><strong>${escapeHtml(state.fee.fee_name)} · Version ${version.version_no}</strong></p><p>${escapeHtml(version.academic_year)} / ${escapeHtml(version.semester)} · ${peso(version.amount)}</p><p class="mb-0">Once activated, its financial configuration can no longer be edited.</p>`, () => api('activate_version', { method: 'POST', ids: { fee_version_id: version.fee_version_id } }), true, state.fee.fee_id);
            if (button.dataset.versionAction === 'archive') confirmAction('Archive Version?', '<p class="mb-0">This version will become permanently read-only.</p>', () => api('archive_version', { method: 'POST', ids: { fee_version_id: version.fee_version_id } }), false, state.fee.fee_id);
        }
    });

    byId('feeSearch').oninput = renderAccordion;
    byId('legacySearch').oninput = renderLegacy;
    byId('addFeeButton').onclick = () => openIdentity();
    byId('legacyFeesButton').onclick = () => {
        showModal('legacyModal');
        loadLegacyFees();
    };
    byId('legacyRetry').onclick = loadLegacyFees;
    byId('archivesButton').onclick = () => { showModal('archivesModal'); loadArchives(); };
    byId('archivesRetry').onclick = loadArchives;
    byId('addScope').onclick = () => addScope(byId('versionScopes'));
    byId('addLegacyScope').onclick = () => addScope(byId('legacyScopes'));
    byId('versionYear').addEventListener('input', validateVersionTermConflict);
    byId('versionTerm').addEventListener('change', validateVersionTermConflict);
    function setFieldError(field, message) {
        const error = byId(`${field.id}Error`);
        field.classList.toggle('is-invalid', Boolean(message));
        field.setCustomValidity(message || '');
        if (error) error.textContent = message || '';
        return !message;
    }

    function requiredField(field, label) {
        return field.value.trim() ? setFieldError(field, '') : setFieldError(field, `${label} is required.`);
    }

    function validateFeeCode(field) {
        const value = field.value.trim();
        if (!value) return setFieldError(field, 'Fee Code is required.');
        return /^[A-Z0-9][A-Z0-9_-]{2,59}$/.test(value)
            ? setFieldError(field, '')
            : setFieldError(field, 'Use 3–60 uppercase letters, numbers, hyphens, or underscores.');
    }

    function validateAcademicYear(field) {
        const match = field.value.trim().match(/^(\d{4})-(\d{4})$/);
        if (!match) return setFieldError(field, 'Use YYYY-YYYY, for example 2027-2028.');
        return Number(match[2]) === Number(match[1]) + 1
            ? setFieldError(field, '')
            : setFieldError(field, 'The ending year must be exactly one year after the starting year.');
    }

    function validateAmount(field) {
        const value = field.value.trim();
        if (!value) return setFieldError(field, 'Version Amount is required.');
        if (!/^\d+(?:\.\d{1,2})?$/.test(value)) return setFieldError(field, 'Enter a valid amount with up to two decimal places.');
        const amount = Number(value);
        return amount >= 0 && amount <= 99999999.99
            ? setFieldError(field, '')
            : setFieldError(field, 'Amount must be between 0.00 and 99,999,999.99.');
    }

    function validateIdentityForm() {
        const code = byId('identityCode'), name = byId('identityName'), group = byId('identityGroup'), type = byId('identityType');
        const isEdit = Boolean(byId('identityId').value);
        const results = [requiredField(name, 'Fee Name'), requiredField(group, 'Fee Group'), requiredField(type, 'Fee Type')];
        if (!isEdit) results.push(validateFeeCode(code));
        return results.every(Boolean);
    }

    function validateLegacyScopes() {
        const scopes = readScopes(byId('legacyScopes'));
        const error = byId('legacyScopesError');
        const valid = scopes.length > 0 && scopes.every(scope => (scope.applies_to_all_courses || scope.course) && (scope.applies_to_all_year_levels || scope.year_level));
        error.classList.toggle('d-none', valid);
        error.textContent = valid ? '' : 'Add at least one complete applicability scope.';
        return valid;
    }

    function validateClassificationForm() {
        const results = [
            validateFeeCode(byId('legacyCode')),
            requiredField(byId('legacyGroup'), 'Fee Group'),
            requiredField(byId('legacyType'), 'Fee Type'),
            validateAcademicYear(byId('legacyYear')),
            requiredField(byId('legacyTerm'), 'Semester'),
            validateAmount(byId('legacyAmount')),
            requiredField(byId('legacyBehavior'), 'Behavior'),
            requiredField(byId('legacyRequired'), 'Required selection'),
            validateLegacyScopes()
        ];
        return results.every(Boolean);
    }

    [byId('identityCode'), byId('legacyCode')].forEach(field => {
        field.oninput = event => {
            event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
            validateFeeCode(event.target);
        };
        field.onblur = () => validateFeeCode(field);
    });
    [byId('identityName'), byId('identityGroup'), byId('identityType')].forEach(field => {
        const validate = () => requiredField(field, field.labels[0]?.textContent.replace('*', '').trim() || 'This field');
        field.addEventListener('input', validate);
        field.addEventListener('change', validate);
        field.addEventListener('blur', validate);
    });
    byId('legacyYear').onblur = () => validateAcademicYear(byId('legacyYear'));
    byId('legacyYear').oninput = () => validateAcademicYear(byId('legacyYear'));
    byId('legacyAmount').onblur = () => validateAmount(byId('legacyAmount'));
    byId('legacyAmount').oninput = () => validateAmount(byId('legacyAmount'));
    [byId('legacyGroup'), byId('legacyType'), byId('legacyTerm'), byId('legacyBehavior'), byId('legacyRequired')].forEach(field => {
        const validate = () => requiredField(field, field.labels[0]?.textContent.replace('*', '').trim() || 'This field');
        field.addEventListener('change', validate);
        field.addEventListener('blur', validate);
    });

    byId('identityForm').onsubmit = async event => {
        event.preventDefault(); if (!validateIdentityForm()) return; const button = byId('identitySave'), feeId = Number(byId('identityId').value);
        const data = { fee_name: byId('identityName').value.trim(), fee_type_id: Number(byId('identityType').value) };
        if (!feeId) Object.assign(data, { fee_code: byId('identityCode').value, identity_status: 'Active', description: byId('identityDescription').value.trim() || null });
        try { setBusy(button, true); const fee = await api(feeId ? 'update_identity' : 'create_identity', { method: 'POST', ids: feeId ? { fee_id: feeId } : {}, data }); hideModal('identityModal'); await refreshCatalog(); alertUser(feeId ? 'Fee configuration updated.' : 'Fee identity created. Add its initial Draft version.'); if (!feeId) openVersionEditor(fee); }
        catch (error) { alertUser(error.message, 'danger'); } finally { setBusy(button, false); }
    };

    byId('newBlankVersion').onclick = () => openVersionEditor(state.fee);
    byId('copyLatestVersion').onclick = () => openVersionEditor(state.fee, null, state.fee.versions[0] || null);
    byId('versionForm').onsubmit = async event => {
        event.preventDefault(); if (!validateVersionTermConflict()) return; const button = byId('versionSave'), feeId = Number(byId('versionFeeId').value), versionId = Number(byId('versionId').value), data = versionPayload('version');
        try { setBusy(button, true); if (versionId) { await api('update_draft_version', { method: 'POST', ids: { fee_version_id: versionId }, data }); await api('replace_draft_applicability', { method: 'POST', ids: { fee_version_id: versionId }, data: { applicability: readScopes(byId('versionScopes')) } }); } else { data.applicability = readScopes(byId('versionScopes')); await api('create_draft_version', { method: 'POST', ids: { fee_id: feeId }, data }); } hideModal('versionEditorModal'); await refreshCatalog(); alertUser('Draft version saved.'); openVersions(await loadManagedFee(feeId)); }
        catch (error) { alertUser(error.message, 'danger'); } finally { setBusy(button, false); }
    };

    byId('confirmAction').onclick = async () => {
        const button = byId('confirmAction');
        try { setBusy(button, true); const { action, refreshFeeId } = state.confirm; await action(); hideModal('confirmModal'); await refreshCatalog(); if (refreshFeeId) openVersions(await loadManagedFee(refreshFeeId)); else hideModal('versionsModal'); alertUser('Action completed.'); }
        catch (error) { hideModal('confirmModal'); alertUser(error.message, 'danger'); } finally { setBusy(button, false); }
    };

    byId('classificationForm').onsubmit = async event => {
        event.preventDefault(); if (!validateClassificationForm()) return; const button = byId('previewClassification');
        try { setBusy(button, true); const preview = await api('preview_legacy_classification', { method: 'POST', ids: { fee_id: Number(byId('legacyFeeId').value) }, data: classificationPayload() }); const proposal = preview.proposed, version = proposal.version; byId('classificationPreviewContent').innerHTML = `<div class="row g-3"><div class="col-sm-5 fee-review-panel"><h6 class="payment-modal-section-title">Old Model</h6><strong>${escapeHtml(state.legacyFee.fee_name)}</strong><div>${escapeHtml(state.legacyFee.category_name || 'Uncategorized')}</div><div>${peso(state.legacyFee.default_amount)} · ${escapeHtml(state.legacyFee.status)}</div></div><div class="col-sm-2 text-center align-self-center fs-2 text-primary">→</div><div class="col-sm-5 fee-review-panel"><h6 class="payment-modal-section-title">New Model</h6><strong>${escapeHtml(proposal.fee_code)}</strong><div>${escapeHtml(byId('legacyType').selectedOptions[0].text)}</div><div>Draft · ${escapeHtml(version.academic_year)} / ${escapeHtml(version.semester)}</div><div>${peso(version.amount)} · ${escapeHtml(version.behavior)}</div><small>${proposal.applicability.map(scopeLabel).map(escapeHtml).join(' • ')}</small></div></div>`; showClassificationPreview(true); }
        catch (error) { alertUser(error.message, 'danger'); } finally { setBusy(button, false); }
    };
    byId('previewBack').onclick = () => showClassificationPreview(false);
    byId('commitClassification').onclick = async () => {
        const button = byId('commitClassification');
        try { const classifiedFeeId = Number(byId('legacyFeeId').value); setBusy(button, true); await api('commit_legacy_classification', { method: 'POST', ids: { fee_id: classifiedFeeId }, data: classificationPayload() }); state.legacy = state.legacy.filter(fee => Number(fee.fee_id) !== classifiedFeeId); hideModal('classificationModal'); await refreshCatalog(); alertUser('Legacy fee classified and its initial Draft version created.'); }
        catch (error) { alertUser(error.message, 'danger'); if (error.code === 'LEGACY_FEE_ALREADY_CLASSIFIED') { hideModal('classificationModal'); await refreshCatalog(); } } finally { setBusy(button, false); }
    };

    renderAccordion();
})();
