document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  if (params.get('tab') !== 'bulk') return;
  const filter = document.getElementById('managedBulkRunsFilter');
  const loadForm = document.getElementById('managedBulkLoadForm');
  const runId = params.get('run_id') || '';
  const status = params.get('bulk_status') || '';
  if (filter && status) {
    let option = [...filter.elements.status.options].find(item => item.value === status);
    if (!option) {
      option = new Option(status === 'Running,CompletedWithExceptions' ? 'Runs Needing Action' : status, status);
      filter.elements.status.add(option);
    }
    filter.elements.status.value = status;
    setTimeout(() => filter.requestSubmit(), 0);
  }
  if (loadForm && runId) {
    loadForm.elements.run_id.value = runId;
    setTimeout(() => loadForm.requestSubmit(), 0);
  }
});
