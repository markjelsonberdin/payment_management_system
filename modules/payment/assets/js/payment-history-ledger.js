/**
 * Payment History & Ledger — filters, ledger modal, loading state
 */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('historyFilterForm');
    const loading = document.getElementById('historyLoadingState');
    const modalEl = document.getElementById('ledgerModal');
    const modalBody = document.getElementById('ledgerModalBody');

    function money(value) {
        const amount = Number(value || 0);
        return '₱ ' + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function text(value, fallback) {
        const raw = (value === null || value === undefined || value === '') ? fallback : String(value);
        const div = document.createElement('div');
        div.textContent = raw;
        return div.innerHTML;
    }

    function formatStamp(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return text(value, '—');
        return date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
    }

    function statusBadge(status) {
        switch (status) {
            case 'Verified':
                return '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-3 py-2 fw-semibold"><i class="fas fa-check-circle me-1"></i>Verified</span>';
            case 'Pending':
                return '<span class="badge rounded-pill bg-warning-subtle text-warning border border-warning-subtle px-3 py-2 fw-semibold"><i class="fas fa-clock me-1"></i>Pending</span>';
            case 'Rejected':
                return '<span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 fw-semibold"><i class="fas fa-times-circle me-1"></i>Rejected</span>';
            case 'Failed':
                return '<span class="badge rounded-pill bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-2 fw-semibold"><i class="fas fa-exclamation-circle me-1"></i>Failed</span>';
            default:
                return '<span class="badge rounded-pill bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-2 fw-semibold">' + text(status, 'Unknown') + '</span>';
        }
    }

    if (form && loading) {
        form.addEventListener('submit', function () {
            loading.classList.remove('d-none');
        });
    }

    document.querySelectorAll('.pagination a.page-link, thead a.text-secondary').forEach(function (link) {
        link.addEventListener('click', function () {
            if (loading) loading.classList.remove('d-none');
        });
    });

    function modalRow(label, value, isHighlight) {
        const valueClass = isHighlight ? 'fw-bold text-primary' : 'fw-semibold text-dark';
        const renderedVal = (String(value).indexOf('₱') === 0 || String(value).indexOf('<') === 0)
            ? value
            : text(value, '—');

        return '<div class="d-flex justify-content-between align-items-center py-2 border-bottom border-light">' +
            '<span class="text-muted small">' + text(label, '') + '</span>' +
            '<span class="' + valueClass + ' text-end small">' + renderedVal + '</span>' +
            '</div>';
    }

    function renderLedger(data) {
        const allocations = Array.isArray(data.allocations) ? data.allocations : [];
        const ledger = data.ledger || {};
        let allocationHtml = '';

        if (allocations.length) {
            allocationHtml = '<div class="table-responsive"><table class="table table-sm table-borderless align-middle mb-0">' +
                '<thead class="text-muted small border-bottom border-light"><tr><th>Fee Item</th><th class="text-end">Allocated</th></tr></thead><tbody>' +
                allocations.map(function (row) {
                    return '<tr><td class="small py-2">' + text(row.fee_name, 'Fee') + '</td><td class="small py-2 text-end fw-bold text-dark">' + money(row.allocated_amount) + '</td></tr>';
                }).join('') +
                '</tbody></table></div>';
        } else {
            allocationHtml = '<p class="text-muted small mb-0 fst-italic py-2 text-center">No specific fee allocations recorded for this transaction.</p>';
        }

        const processedBy = data.verified_by
            ? ('Staff ID #' + text(data.verified_by, ''))
            : 'Gateway / Direct Payment';

        modalBody.innerHTML =
            // Hero card
            '<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">' +
                '<div class="card-body p-4 text-center">' +
                    '<div class="text-uppercase fw-bold text-muted small mb-1" style="letter-spacing: 0.5px;">Total Payment Amount</div>' +
                    '<h2 class="fw-bolder text-primary mb-2">' + money(data.checkout_total) + '</h2>' +
                    '<div class="mb-2">' + statusBadge(data.payment_status) + '</div>' +
                    '<div class="text-muted small">Ref: <span class="fw-semibold text-dark">#' + text(data.reference_number, 'N/A') + '</span>' +
                        (data.receipt_number ? ' · OR: <span class="fw-semibold text-dark">#' + text(data.receipt_number, '') + '</span>' : '') +
                    '</div>' +
                '</div>' +
            '</div>' +

            // Details and Ledger Breakdown
            '<div class="row g-3 mb-3">' +
                // Student & Transaction Info
                '<div class="col-md-6">' +
                    '<div class="card border-0 shadow-sm rounded-3 h-100 bg-white">' +
                        '<div class="card-body p-3">' +
                            '<h6 class="fw-bold text-secondary text-uppercase mb-2" style="font-size: 0.72rem; letter-spacing: 0.5px;"><i class="fas fa-user-graduate me-1 text-primary"></i> Student & Details</h6>' +
                            modalRow('Student Name', data.full_name) +
                            modalRow('Student No.', data.student_number) +
                            modalRow('Course / Program', data.course || '—') +
                            modalRow('Payment Channel', data.payment_channel) +
                            modalRow('Transaction Type', data.transaction_type || 'Tuition / Fees') +
                            modalRow('Billing Term', [data.billing_type, data.academic_year, data.semester].filter(Boolean).join(' · ') || '—') +
                        '</div>' +
                    '</div>' +
                '</div>' +

                // Payment & Ledger Balances
                '<div class="col-md-6">' +
                    '<div class="card border-0 shadow-sm rounded-3 h-100 bg-white">' +
                        '<div class="card-body p-3">' +
                            '<h6 class="fw-bold text-secondary text-uppercase mb-2" style="font-size: 0.72rem; letter-spacing: 0.5px;"><i class="fas fa-calculator me-1 text-primary"></i> Payment & Ledger</h6>' +
                            modalRow('Amount Applied', money(data.amount)) +
                            modalRow('Processing Fee', money(data.processing_fee)) +
                            modalRow('Checkout Total', money(data.checkout_total), true) +
                            modalRow('Opening Balance', money(ledger.opening_balance)) +
                            modalRow('Total Payments', money(ledger.payments_total)) +
                            modalRow('Closing Balance', money(ledger.closing_balance), true) +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +

            // Allocations breakdown
            '<div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">' +
                '<div class="card-body p-3">' +
                    '<h6 class="fw-bold text-secondary text-uppercase mb-2" style="font-size: 0.72rem; letter-spacing: 0.5px;"><i class="fas fa-list-check me-1 text-primary"></i> Fee Allocation Breakdown</h6>' +
                    allocationHtml +
                '</div>' +
            '</div>' +

            // Audit Trail
            '<div class="card border-0 shadow-sm rounded-3 bg-white">' +
                '<div class="card-body p-3">' +
                    '<h6 class="fw-bold text-secondary text-uppercase mb-2" style="font-size: 0.72rem; letter-spacing: 0.5px;"><i class="fas fa-shield-alt me-1 text-primary"></i> Audit Information</h6>' +
                    modalRow('Payment Date', text(data.payment_date, '—')) +
                    modalRow('Recorded At', formatStamp(data.created_at)) +
                    modalRow('Verified At', data.verified_at ? formatStamp(data.verified_at) : 'Pending Verification') +
                    modalRow('Processed By', processedBy) +
                    modalRow('Remarks', data.remarks || 'None') +
                '</div>' +
            '</div>';
    }

    document.querySelectorAll('.js-view-ledger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            let data = {};
            try {
                data = JSON.parse(btn.getAttribute('data-ledger') || '{}');
            } catch (err) {
                data = {};
            }
            renderLedger(data);
            if (window.bootstrap && modalEl) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });
    });
});
