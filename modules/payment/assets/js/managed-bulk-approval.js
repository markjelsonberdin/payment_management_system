document.addEventListener('DOMContentLoaded', () => {
  const app = document.getElementById('bulkApprovalApp');
  if (!app) return;

  const form = document.getElementById('bulkApprovalLoad');
  const out = document.getElementById('bulkApprovalResult');
  const pending = document.getElementById('pendingApprovalList');
  const refresh = document.getElementById('refreshPendingApprovals');
  const approveModalElement = document.getElementById('approveRunModal');
  const approveModal = window.bootstrap ? new bootstrap.Modal(approveModalElement) : null;
  const confirmApprove = document.getElementById('confirmApproveRun');
  const approveModalBody = document.getElementById('approveRunModalBody');
  let queuePage = 1;
  let queuePageSize = 10;
  let currentRun = null;
  let assignmentPage = 1;
  let assignmentPageSize = 25;
  let assignmentStudentSearch = '';
  let assignmentStatus = '';
  const assignmentStatuses = ['Pending', 'Processing', 'Added', 'PartiallyAdded', 'AlreadyAssessed', 'NoApplicableFees', 'ReviewRequired', 'Failed'];

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
  const peso = value => `&#8369;${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const displayName = (name, id) => String(name || '').trim() || `User #${id}`;
  const displayDate = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

  async function call(action, payload = {}) {
    const response = await fetch(app.dataset.api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.dataset.csrf },
      body: JSON.stringify({ action, ...payload })
    });
    const body = await response.json();
    if (!response.ok || !body.ok) throw new Error(body.message || body.error || 'Request failed.');
    if (body.csrf_token) app.dataset.csrf = body.csrf_token;
    return body.data;
  }

  function pager(meta, onPage) {
    if (!meta || meta.total_pages <= 1) return '';
    const pageItem = (page, label = String(page), active = false, disabled = false, ariaLabel = '') =>
      `<li class="page-item${active ? ' active' : ''}${disabled ? ' disabled' : ''}">${active || disabled
        ? `<span class="page-link"${active ? ' aria-current="page"' : ' aria-disabled="true"'}>${label}</span>`
        : `<button class="page-link" type="button" data-page="${page}"${ariaLabel ? ` aria-label="${ariaLabel}"` : ''}>${label}</button>`}</li>`;
    const start = Math.max(1, meta.page - 2), end = Math.min(meta.total_pages, meta.page + 2);
    let items = pageItem(meta.page - 1, '‹ Previous', false, meta.page <= 1, 'Previous page');
    if (start > 1) {
      items += pageItem(1);
      if (start > 2) items += '<li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li>';
    }
    for (let page = start; page <= end; page += 1) items += pageItem(page, String(page), page === meta.page);
    if (end < meta.total_pages) {
      if (end < meta.total_pages - 1) items += '<li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li>';
      items += pageItem(meta.total_pages);
    }
    items += pageItem(meta.page + 1, 'Next ›', false, meta.page >= meta.total_pages, 'Next page');
    return `<nav class="payment-pagination mt-3" aria-label="Managed billing pages"><ul class="pagination pagination-sm mb-0">${items}</ul></nav>`;
  }

  async function loadRun(runId, resetFilters = true) {
    if (resetFilters) { assignmentPage = 1; assignmentStudentSearch = ''; assignmentStatus = ''; }
    const data = await call('detail', {
      run_id: Number(runId), assignment_page: assignmentPage, assignment_page_size: assignmentPageSize,
      assignment_student_search: assignmentStudentSearch, assignment_status: assignmentStatus
    });
    currentRun = data;
    renderRun(data);
    out.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function loadPending() {
    pending.innerHTML = '<div class="text-muted small">Loading pending approvals…</div>';
    try {
      const data = await call('list_runs', { status: 'Draft', page: queuePage, page_size: queuePageSize });
      const runs = data.runs || [], meta = data.pagination || {};
      if (!runs.length) {
        pending.innerHTML = '<div class="alert alert-light border mb-0">No Draft bulk runs are waiting for approval.</div>';
        return;
      }
      pending.innerHTML = `<div class="d-flex justify-content-between align-items-center mb-2"><span class="small text-muted">Showing ${((meta.page - 1) * meta.page_size) + 1} to ${Math.min(meta.page * meta.page_size, meta.total_rows)} of ${meta.total_rows} Draft run(s)</span><select class="form-select form-select-sm" id="pendingPageSize" style="width:auto"><option value="10">10 / page</option><option value="25">25 / page</option><option value="50">50 / page</option><option value="100">100 / page</option></select></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Run</th><th>Term</th><th>Created by</th><th>Created at</th><th>Cohort</th><th>Gross estimate</th><th class="text-end">Action</th></tr></thead><tbody>${runs.map(run => `<tr><td>#${esc(run.run_id)}</td><td>${esc(run.academic_year)} / ${esc(run.semester)}</td><td>${esc(displayName(run.initiated_by_name_snapshot, run.initiated_by))}</td><td>${esc(displayDate(run.created_at))}</td><td>${esc(run.projected_student_count)}</td><td>${peso(run.projected_amount)}</td><td class="text-end"><button class="btn btn-primary btn-sm" type="button" data-review-run="${esc(run.run_id)}">Review &amp; approve</button></td></tr>`).join('')}</tbody></table></div>${pager(meta, page => { queuePage = page; loadPending(); })}`;
      document.getElementById('pendingPageSize').value = String(queuePageSize);
      document.getElementById('pendingPageSize').addEventListener('change', event => { queuePageSize = Number(event.target.value); queuePage = 1; loadPending(); });
      pending.querySelectorAll('[data-review-run]').forEach(button => button.addEventListener('click', async () => {
        try { await loadRun(button.dataset.reviewRun); }
        catch (error) { out.innerHTML = `<div class="alert alert-danger">${esc(error.message)}</div>`; }
      }));
      pending.querySelectorAll('[data-page]').forEach(button => button.addEventListener('click', () => { queuePage = Number(button.dataset.page); loadPending(); }));
    } catch (error) {
      pending.innerHTML = `<div class="alert alert-danger mb-0">${esc(error.message)}</div>`;
    }
  }

  function selectionBadge(fee) {
    if (fee.behavior === 'Standard' && Number(fee.is_required) === 1) return '<span class="badge text-bg-primary">Required</span>';
    if (fee.selection_state === 'Selected') return '<span class="badge text-bg-success">Selected</span>';
    return '<span class="badge text-bg-secondary">Excluded</span>';
  }

  function renderRun(data) {
    const run = data.run, fees = data.fee_versions || [], assignments = data.assignments || [];
    const meta = data.assignment_pagination || {}, filters = data.assignment_filters || {};
    const selectedFees = fees.filter(fee => fee.selection_state === 'Selected').length;
    const creator = displayName(run.initiated_by_name_snapshot, run.initiated_by);
    const approver = run.approved_by ? displayName(run.approved_by_name_snapshot, run.approved_by) : '';
    const statusClass = run.status === 'Draft' ? 'text-bg-warning' : run.status === 'Approved' ? 'text-bg-success' : 'text-bg-secondary';
    out.innerHTML = `<article class="border rounded p-3"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2 border-bottom pb-3 mb-3"><div><div class="d-flex align-items-center gap-2"><h2 class="h5 mb-0">Run #${esc(run.run_id)}</h2><span class="badge ${statusClass}">${esc(run.status)}</span></div><p class="small text-muted mb-0 mt-1">Managed bulk billing approval record</p></div><strong>Gross estimate: ${peso(run.projected_amount)}</strong></div><div class="row row-cols-1 row-cols-md-3 g-3 small mb-4"><div><span class="text-muted d-block">AY / Semester</span><strong>${esc(run.academic_year)} / ${esc(run.semester)}</strong></div><div><span class="text-muted d-block">Students</span><strong>${esc(run.projected_student_count)}</strong></div><div><span class="text-muted d-block">Created by</span><strong>${esc(creator)}</strong><span class="d-block text-muted">${esc(displayDate(run.created_at))}</span></div></div>${run.status !== 'Draft' && run.approved_by ? `<div class="alert alert-success py-2 small"><strong>Approved by ${esc(approver)}</strong> on ${esc(displayDate(run.approved_at))}. This run is ready for Accounting Officer processing.</div>` : ''}<h3 class="h6">Fee Snapshot</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Fee</th><th>Behavior</th><th>Amount</th><th>Selection</th></tr></thead><tbody>${fees.map(fee => `<tr><td>${esc(fee.fee_name_snapshot || fee.preview_name_snapshot)}</td><td>${esc(fee.behavior_snapshot || fee.behavior)}</td><td>${peso(fee.amount_snapshot || fee.preview_amount_snapshot)}</td><td>${selectionBadge(fee)}</td></tr>`).join('')}</tbody></table></div><div class="d-flex flex-wrap justify-content-between gap-2 mt-4"><h3 class="h6 mb-0">Cohort Assignments (${meta.total_rows ?? assignments.length})</h3><div class="d-flex gap-2"><input id="assignmentStudentSearch" class="form-control form-control-sm" style="min-width:200px" placeholder="Search student…" value="${esc(filters.student_search || '')}"><select id="assignmentStatus" class="form-select form-select-sm"><option value="">All statuses</option>${assignmentStatuses.map(status => `<option value="${status}" ${filters.status === status ? 'selected' : ''}>${status}</option>`).join('')}</select><select id="assignmentPageSize" class="form-select form-select-sm"><option value="25">25 / page</option><option value="50">50 / page</option><option value="100">100 / page</option></select><button id="assignmentFilter" type="button" class="btn btn-sm btn-outline-primary">Filter</button></div></div><div class="table-responsive mt-2"><table class="table table-sm"><thead><tr><th>Student No.</th><th>Student</th><th>Program / Year</th><th>Enrollment</th><th>Status</th></tr></thead><tbody>${assignments.length ? assignments.map(a => `<tr><td>${esc(a.student_number)}</td><td>${esc(a.student_full_name)}</td><td>${esc(a.program_code)} / ${esc(a.year_level)}</td><td>${esc(a.enrollment_status)}</td><td>${esc(a.status)}</td></tr>`).join('') : '<tr><td colspan="5" class="text-center text-muted py-3">No assignments match this filter.</td></tr>'}</tbody></table></div><div class="small text-muted">${meta.total_rows ? `Showing ${((meta.page - 1) * meta.page_size) + 1} to ${Math.min(meta.page * meta.page_size, meta.total_rows)} of ${meta.total_rows} entries` : 'Showing 0 entries'}</div>${pager(meta, page => { assignmentPage = page; loadRun(run.run_id, false); })}${run.status === 'Draft' ? `<section class="border rounded bg-light p-3 mt-4"><h3 class="h6">Approval Summary</h3><p class="mb-1"><strong>${esc(run.projected_student_count)}</strong> students · <strong>${selectedFees}</strong> selected fee versions</p><p class="mb-2">Gross estimate: <strong>${peso(run.projected_amount)}</strong></p><p class="small text-muted mb-3">Actual student charges remain revalidated by Phase 4 during Accounting Officer processing.</p><button type="button" class="btn btn-success" id="openApproveModal">Approve Draft</button></section>` : ''}</article>`;
    const assignmentTable = out.querySelectorAll('table')[1];
    if (assignmentTable) {
      const heading = document.createElement('th'); heading.textContent = 'Notification'; assignmentTable.querySelector('thead tr')?.append(heading);
      if (assignments.length) assignmentTable.querySelectorAll('tbody tr').forEach((row, index) => { const cell = document.createElement('td'); cell.textContent = assignments[index]?.notification_state || 'NotRequired'; row.append(cell); });
      else assignmentTable.querySelector('tbody td')?.setAttribute('colspan', '6');
    }
    document.getElementById('assignmentPageSize').value = String(assignmentPageSize);
    document.getElementById('assignmentFilter').addEventListener('click', () => {
      assignmentStudentSearch = document.getElementById('assignmentStudentSearch').value.trim();
      assignmentStatus = document.getElementById('assignmentStatus').value;
      assignmentPageSize = Number(document.getElementById('assignmentPageSize').value);
      assignmentPage = 1; loadRun(run.run_id, false);
    });
    document.getElementById('assignmentStudentSearch').addEventListener('keydown', event => { if (event.key === 'Enter') document.getElementById('assignmentFilter').click(); });
    out.querySelectorAll('[data-page]').forEach(button => button.addEventListener('click', () => { assignmentPage = Number(button.dataset.page); loadRun(run.run_id, false); }));
    const openModal = document.getElementById('openApproveModal');
    if (openModal) openModal.addEventListener('click', () => {
      approveModalBody.innerHTML = `<p>Approve managed bulk run <strong>#${esc(run.run_id)}</strong>?</p><dl class="row small mb-0"><dt class="col-5">Students</dt><dd class="col-7">${esc(run.projected_student_count)}</dd><dt class="col-5">Selected fees</dt><dd class="col-7">${selectedFees}</dd><dt class="col-5">Gross estimate</dt><dd class="col-7">${peso(run.projected_amount)}</dd></dl><p class="small text-muted mt-3 mb-0">This authorizes Accounting Officers to process the frozen cohort and fee snapshot.</p>`;
      if (approveModal) approveModal.show();
    });
  }

  confirmApprove.addEventListener('click', async () => {
    if (!currentRun?.run || currentRun.run.status !== 'Draft') return;
    confirmApprove.disabled = true;
    try {
      const data = await call('approve', { run_id: Number(currentRun.run.run_id) });
      if (approveModal) approveModal.hide();
      renderRun(data); await loadPending();
    } catch (error) {
      approveModalBody.insertAdjacentHTML('beforeend', `<div class="alert alert-danger mt-3 mb-0">${esc(error.message)}</div>`);
    } finally { confirmApprove.disabled = false; }
  });
  form.onsubmit = async event => { event.preventDefault(); try { await loadRun(Number(new FormData(form).get('run_id'))); } catch (error) { out.innerHTML = `<div class="alert alert-danger">${esc(error.message)}</div>`; } };
  refresh.addEventListener('click', () => { queuePage = 1; loadPending(); });
  loadPending();
});
