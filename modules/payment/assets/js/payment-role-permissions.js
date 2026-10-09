document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const app = document.getElementById('permissionMatrixApp');
    if (!app) return;

    const id = (value) => document.getElementById(value);
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
    const humanize = (value) => String(value ?? '').split('.').map((part) =>
        part.replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase())
    ).join(' / ');
    const state = {
        data: null, role: '', original: {}, draft: {}, module: '', search: '', page: 1,
        pageSize: 5, saving: false, authoritative: false, reconciliationRequired: false
    };
    const roleRows = () => state.data ? state.data.rows.filter((row) => row.role === state.role) : [];
    const changes = () => roleRows().filter((row) =>
        Boolean(state.draft[row.permission]) !== Boolean(state.original[row.permission])
    );
    const badge = (value) => {
        const presentation = {
            full: ['success', 'Full Access'], allowed: ['success', 'Allowed'],
            partial: ['primary', 'Partial Access'], denied: ['secondary', 'Denied'],
            protected: ['warning text-dark', 'Protected'], unavailable: ['warning text-dark', 'Unavailable']
        }[value] || ['secondary', humanize(value)];
        return '<span class="badge mis-status-badge bg-' + presentation[0] + '">' + escapeHtml(presentation[1]) + '</span>';
    };
    function notice(message = '', kind = 'danger') {
        const element = id('permissionNotice');
        element.className = message ? 'alert alert-' + kind + ' py-2 px-3 mb-3' : 'd-none';
        element.textContent = message;
    }
    function canEdit() {
        return state.authoritative && state.data.authorization_available && state.data.duplicates.length === 0;
    }
    function renderTabs() {
        id('permissionRoleTabs').innerHTML = [{ key: '', label: 'All' }, ...state.data.roles].map((role) =>
            '<button type="button" class="btn ' + (state.role === role.key ? 'btn-primary' : 'btn-outline-primary') + '" data-role="' + escapeHtml(role.key) + '">' + escapeHtml(role.label) + '</button>'
        ).join('');
        id('permissionRoleTabs').querySelectorAll('[data-role]').forEach((button) =>
            button.addEventListener('click', () => selectRole(button.dataset.role))
        );
    }
    function renderSummary() {
        const summary = state.data.summaries[state.role || 'all'];
        id('permissionSummary').innerHTML = state.data.summary_cards.map((card) => {
            const label = state.role ? card.label : card.all_label;
            return '<div class="col-6 col-xl-3"><div class="mis-stat-card"><div class="mis-stat-label">' + escapeHtml(label) + '</div><div class="mis-stat-value text-' + escapeHtml(card.tone) + '">' + Number(summary[card.key] || 0) + '</div></div></div>';
        }).join('');
    }
    function renderAll() {
        id('permissionModuleHead').innerHTML = '<tr><th>Module</th>' + state.data.roles.map((role) => '<th>' + escapeHtml(role.label) + '</th>').join('') + '</tr>';
        id('permissionModuleRows').innerHTML = state.data.modules.map((module) =>
            '<tr><td><span class="fw-semibold">' + escapeHtml(module.module) + '</span><div class="small text-muted">' + module.permissions.length + ' registered capabilities</div></td>' +
            state.data.roles.map((role) => {
                const aggregate = module.role_aggregates[role.key];
                const cells = state.data.rows.filter((row) => row.module === module.module && row.role === role.key);
                const sources = [...new Set(cells.map((row) => humanize(row.source)))];
                return '<td>' + badge(aggregate.state) + '<div class="small text-muted mt-1">' + escapeHtml(aggregate.allowed_count + ' of ' + aggregate.applicable_count + ' allowed') + '</div><div class="small text-muted">' + escapeHtml(sources.join(', ')) + (aggregate.protected_count ? ' / ' + aggregate.protected_count + ' protected' : '') + '</div></td>';
            }).join('') + '</tr>'
        ).join('');
    }
    function moduleNames() {
        return [...new Set(roleRows().map((row) => row.module))].sort();
    }
    function renderEditor() {
        const filtered = roleRows().filter((row) => {
            const matchesModule = !state.module || row.module === state.module;
            const haystack = [row.module, row.capability_label || humanize(row.permission), row.permission].join(' ').toLowerCase();
            return matchesModule && (!state.search || haystack.includes(state.search));
        });
        const allModules = [...new Set(filtered.map((row) => row.module))];
        const pages = Math.max(1, Math.ceil(allModules.length / state.pageSize));
        state.page = Math.min(state.page, pages);
        const visibleModules = allModules.slice((state.page - 1) * state.pageSize, state.page * state.pageSize);
        const groups = {};
        filtered.filter((row) => visibleModules.includes(row.module)).forEach((row) => (groups[row.module] ??= []).push(row));
        id('permissionEditor').innerHTML = Object.entries(groups).map(([module, items]) => {
            const aggregate = state.data.modules.find((item) => item.module === module)?.role_aggregates[state.role];
            const summaryChecked = aggregate && ['full', 'partial'].includes(aggregate.state);
            return '<section class="card mis-table-card mb-3"><div class="card-header bg-body d-flex justify-content-between align-items-center gap-2"><div><span class="fw-semibold">' + escapeHtml(module) + '</span><div class="small text-muted">' + items.length + ' capability(s)</div></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-module-summary="' + escapeHtml(module) + '" aria-label="' + escapeHtml('Module access summary: ' + (aggregate?.allowed_count ?? 0) + ' of ' + (aggregate?.applicable_count ?? 0) + ' allowed') + '" title="Summary only - edit individual capabilities below" ' + (summaryChecked ? 'checked ' : '') + 'disabled><label class="form-check-label small">Module access summary</label></div></div><div class="list-group list-group-flush">' +
                items.map((row) => {
                    const editable = row.editable && canEdit();
                    const current = Boolean(state.draft[row.permission]);
                    const displayState = editable ? (current ? 'allowed' : 'denied') : (!row.available ? 'unavailable' : row.effective_access);
                    return '<label class="list-group-item d-flex align-items-start gap-3 ' + (!editable ? 'text-muted' : '') + '"><input class="form-check-input mt-1" type="checkbox" data-permission="' + escapeHtml(row.permission) + '" ' + (current ? 'checked ' : '') + (editable ? '' : 'disabled ') + '><span class="flex-grow-1"><span class="d-block fw-medium">' + escapeHtml(row.capability_label || humanize(row.permission)) + '</span><code class="mis-code">' + escapeHtml(row.permission) + '</code><span class="d-block small">' + escapeHtml(humanize(row.source)) + (row.protected ? ' / Protected by role policy' : '') + '</span></span><span>' + badge(displayState) + '</span></label>';
                }).join('') + '</div></section>';
        }).join('') || '<div class="alert alert-secondary">No capabilities match the filters.</div>';

        id('permissionEditor').querySelectorAll('[data-permission]').forEach((checkbox) => checkbox.addEventListener('change', () => {
            state.draft[checkbox.dataset.permission] = checkbox.checked;
            renderEditor();
            renderDirty();
        }));
        const pagination = id('permissionRolePagination');
        pagination.innerHTML = Array.from({ length: pages }, (_, index) => '<li class="page-item ' + (index + 1 === state.page ? 'active' : '') + '"><button class="page-link" type="button" data-page="' + (index + 1) + '">' + (index + 1) + '</button></li>').join('');
        pagination.querySelectorAll('[data-page]').forEach((button) => button.addEventListener('click', () => {
            state.page = Number(button.dataset.page);
            renderEditor();
        }));
        renderDirty();
    }
    function renderDirty() {
        const count = changes().length;
        const blocked = !canEdit() || state.saving || state.reconciliationRequired;
        id('permissionDirtyCount').textContent = count ? count + ' unsaved change(s).' : 'No unsaved changes.';
        id('permissionPreview').disabled = !count || !canEdit() || state.saving;
        id('permissionSave').disabled = !count || blocked;
        id('permissionResetChanges').disabled = !count || state.saving;
    }
    function initializeRole(role) {
        state.role = role;
        state.module = '';
        state.search = '';
        state.page = 1;
        state.original = {};
        state.draft = {};
        state.reconciliationRequired = false;
        id('permissionFilters').reset();
        if (role) roleRows().forEach((row) => {
            const granted = row.effective_access === 'allowed';
            state.original[row.permission] = granted;
            state.draft[row.permission] = granted;
        });
        renderTabs();
        renderSummary();
        id('permissionAllView').classList.toggle('d-none', Boolean(role));
        id('permissionRoleView').classList.toggle('d-none', !role);
        if (role) {
            const selected = state.data.roles.find((item) => item.key === role);
            id('permissionRoleHeading').textContent = (selected ? selected.label : role) + ' permissions';
            id('permissionModule').innerHTML = '<option value="">All modules</option>' + moduleNames().map((module) => '<option>' + escapeHtml(module) + '</option>').join('');
            renderEditor();
        } else renderAll();
    }
    function selectRole(role) {
        if (role === state.role) return;
        if (changes().length && !confirm('Discard unsaved permission changes?')) return;
        initializeRole(role);
    }
    function preview() {
        const pending = changes();
        const role = state.data.roles.find((item) => item.key === state.role);
        id('permissionPreviewRole').textContent = (role ? role.label : state.role) + ' / ' + pending.length + ' change(s)' + (state.reconciliationRequired ? ' / Latest server version loaded; review before saving.' : '');
        id('permissionPreviewRows').innerHTML = pending.length ? '<div class="list-group">' + pending.map((row) =>
            '<div class="list-group-item d-flex justify-content-between gap-3"><div><strong>' + escapeHtml(row.module) + '</strong><br><span>' + escapeHtml(row.capability_label || humanize(row.permission)) + '</span><br><code>' + escapeHtml(row.permission) + '</code></div><div><span class="badge bg-' + (state.original[row.permission] ? 'success' : 'secondary') + '">' + (state.original[row.permission] ? 'Allowed' : 'Denied') + '</span> <i class="fas fa-arrow-right mx-1"></i> <span class="badge bg-' + (state.draft[row.permission] ? 'success' : 'secondary') + '">' + (state.draft[row.permission] ? 'Allowed' : 'Denied') + '</span></div></div>'
        ).join('') + '</div>' : '<p>No changes to save.</p>';
        id('permissionConfirmSave').textContent = state.reconciliationRequired ? 'I reviewed latest permissions' : 'Confirm and save';
        bootstrap.Modal.getOrCreateInstance(id('permissionPreviewModal')).show();
    }
    function uuid() {
        if (globalThis.crypto?.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
            const random = Math.random() * 16 | 0;
            return (char === 'x' ? random : (random & 3 | 8)).toString(16);
        });
    }
    async function load(role = '', options = {}) {
        app.setAttribute('aria-busy', 'true');
        state.authoritative = false;
        renderDirty();
        id('permissionAllView').classList.add('d-none');
        id('permissionRoleView').classList.add('d-none');
        id('permissionSummary').innerHTML = '<div class="col-12 text-muted">Loading authoritative permission data...</div>';
        id('permissionRefresh').disabled = true;
        notice();
        try {
            const response = await fetch(app.dataset.api, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            const body = await response.json();
            if (!response.ok || !body.ok) throw new Error(body.message || 'Permission information is unavailable.');
            state.data = body.data;
            state.authoritative = Boolean(body.data.authorization_available) && body.data.duplicates.length === 0;
            if (options.preserveDraft && role) {
                const latest = {};
                body.data.rows.filter((row) => row.role === role).forEach((row) => { latest[row.permission] = row.effective_access === 'allowed'; });
                state.original = latest;
                state.role = role;
                state.reconciliationRequired = Boolean(options.reconcile);
                renderTabs();
                renderSummary();
                id('permissionAllView').classList.add('d-none');
                id('permissionRoleView').classList.remove('d-none');
                const selected = body.data.roles.find((item) => item.key === role);
                id('permissionRoleHeading').textContent = (selected ? selected.label : role) + ' permissions';
                id('permissionModule').innerHTML = '<option value="">All modules</option>' + moduleNames().map((module) => '<option>' + escapeHtml(module) + '</option>').join('');
                renderEditor();
            } else initializeRole(role);
            if (!body.data.authorization_available) notice('Core permission records are unavailable. Editing is disabled.','warning');
            else if (body.data.duplicates.length) notice('Duplicate permission records were found. Saving is disabled until they are repaired.','warning');
            else if (options.reconcile) notice('Permissions changed in another session. Latest values are loaded; review your draft and reconcile it before saving.','warning');
            return true;
        } catch (error) {
            state.authoritative = false;
            renderDirty();
            notice(error.message || 'Permission information is unavailable.', 'warning');
            id('permissionAllView').classList.add('d-none');
            id('permissionRoleView').classList.add('d-none');
            id('permissionSummary').innerHTML = '<div class="col-12"><div class="alert alert-warning mb-0">Authoritative permission data is unavailable. Your draft is retained; refresh before viewing or editing permissions.</div></div>';
            return false;
        } finally {
            app.removeAttribute('aria-busy');
            id('permissionRefresh').disabled = false;
        }
    }
    async function save() {
        if (!changes().length || !canEdit() || state.saving || state.reconciliationRequired) return;
        state.saving = true;
        renderDirty();
        notice();
        try {
            const response = await fetch(app.dataset.updateApi, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': app.dataset.csrf },
                body: JSON.stringify({ role: state.role, decisions: state.draft, expected_version: state.data.versions[state.role], correlation_id: uuid() })
            });
            const body = await response.json();
            if (!response.ok || !body.ok) {
                if (response.status === 409 && body.error === 'PERMISSION_VERSION_CONFLICT') {
                    state.reconciliationRequired = true;
                    await load(state.role, { preserveDraft: true, reconcile: true });
                    return;
                }
                throw new Error(body.message || body.error || 'Permission update failed.');
            }
            bootstrap.Modal.getInstance(id('permissionPreviewModal'))?.hide();
            const savedRole = state.role;
            const savedDraft = { ...state.draft };
            state.original = { ...savedDraft };
            state.draft = { ...savedDraft };
            notice('Permissions were saved. Refreshing the authoritative matrix...', 'success');
            const refreshed = await load(savedRole);
            if (refreshed) notice('Permissions saved. ' + Number(body.data.changed) + ' effective decision(s) changed.','success');
            else notice('Permissions were saved, but the latest matrix could not be loaded. Editing is disabled until you refresh authoritative data.','warning');
        } catch (error) {
            notice(error.message || 'Permission update failed. Your draft has been retained.');
        } finally {
            state.saving = false;
            renderDirty();
        }
    }

    id('permissionFilters').addEventListener('submit', (event) => {
        event.preventDefault();
        state.module = id('permissionModule').value;
        state.page = 1;
        state.search = id('permissionSearch').value.trim().toLowerCase();
        renderEditor();
    });
    id('permissionResetFilter').addEventListener('click', () => {
        id('permissionFilters').reset();
        state.module = '';
        state.search = '';
        state.page = 1;
        renderEditor();
    });
    id('permissionResetChanges').addEventListener('click', () => {
        state.draft = { ...state.original };
        state.reconciliationRequired = false;
        renderEditor();
        notice('Draft reset to the latest confirmed server values.', 'info');
    });
    id('permissionPreview').addEventListener('click', preview);
    id('permissionSave').addEventListener('click', preview);
    id('permissionConfirmSave').addEventListener('click', () => {
        if (state.reconciliationRequired) {
            state.reconciliationRequired = false;
            bootstrap.Modal.getInstance(id('permissionPreviewModal'))?.hide();
            notice('Latest permissions reviewed. Select Save permissions and confirm again to submit the reconciled draft.', 'info');
            renderDirty();
            return;
        }
        save();
    });
    id('permissionRefresh').addEventListener('click', () => {
        if (changes().length && !confirm('Discard unsaved permission changes and reload?')) return;
        load(state.role);
    });
    load();
});
