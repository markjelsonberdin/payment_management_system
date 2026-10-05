document.addEventListener('DOMContentLoaded', function () {
    const btnSearch = document.getElementById('btnSearchStudent');
    const searchInput = document.getElementById('searchStudentNumber');
    const billingInfoBox = document.getElementById('studentBillingInfo');
    const paymentPanel = document.getElementById('paymentPanel');
    const inputAmountPaid = document.getElementById('inputAmountPaid');
    const inputCashReceived = document.getElementById('inputCashReceived');
    const lblChangeAmount = document.getElementById('lblChangeAmount');
    const btnProcess = document.getElementById('btnProcessPayment');
    const lookupError = document.getElementById('studentLookupError');
    const billingId = document.getElementById('inputBillingId');
    const studentId = document.getElementById('inputStudentId');
    const contextSelect = document.getElementById('inputPaymentContext');
    const categorySelect = document.getElementById('inputCategoryId');
    const categoryWrapper = document.getElementById('categorySelectionWrapper');
    let activeBalance = 0;
    let lookupVersion = 0;
    let lookupController = null;
    let lookupPending = false;
    let requestedStudentNumber = '';
    let activeStudentNumber = '';
    const prefillStudent = new URLSearchParams(window.location.search).get('student_number');
    const money = value => '₱ ' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2 });

    function lockPaymentPanel() {
        activeBalance = 0;
        activeStudentNumber = '';
        billingId.value = '';
        studentId.value = '';
        categorySelect.replaceChildren(new Option('Select unpaid category', ''));
        billingInfoBox.classList.add('d-none');
        paymentPanel.style.pointerEvents = 'none';
        paymentPanel.classList.add('opacity-50');
        inputAmountPaid.value = '';
        if (inputCashReceived) inputCashReceived.value = '';
        lblChangeAmount.textContent = '₱ 0.00';
        btnProcess.disabled = true;
        updateCategoryContext();
    }

    function updateCategoryContext() {
        const specific = contextSelect.value === 'CATEGORY_PRIORITY';
        categoryWrapper.classList.toggle('d-none', !specific);
        categorySelect.required = specific;
        if (!specific) categorySelect.value = '';
        validateAndCompute();
    }

    function appendBreakdown(data) {
        const container = document.getElementById('unpaidFeesContainer');
        const list = document.getElementById('unpaidFeesList');
        list.innerHTML = '';
        if (!data) { container.classList.add('d-none'); return false; }
        if (data.has_error) {
            list.innerHTML = `<div class="alert alert-danger p-2 small mb-0"><i class="ti ti-alert-triangle me-1"></i> ${data.error_message || 'Billing information needs review.'}</div>`;
            container.classList.remove('d-none');
            return true;
        }
        const categories = Array.isArray(data.categories) ? data.categories : [];
        let grandTotal = 0;
        let html = '';
        categories.forEach(category => {
            const categoryTotal = Number(category.category_total || 0);
            grandTotal += categoryTotal;
            html += `<details class="border rounded mb-2 bg-white"><summary class="d-flex justify-content-between align-items-center px-2 py-2 fw-bold" style="cursor:pointer"><span>${category.category_name}</span><span class="text-danger">${money(categoryTotal)}</span></summary><div class="border-top px-2 pt-2 pb-1">`;
            (Array.isArray(category.fees) ? category.fees : []).forEach(fee => {
                const source = fee.source_context ? `<span class="badge bg-light text-secondary border ms-1">${fee.source_context}</span>` : '';
                html += `<div class="d-flex justify-content-between gap-2 align-items-start mb-2 small"><span>${fee.fee_name}${source}</span><strong>${money(fee.remaining_amount)}</strong></div>`;
            });
            html += '</div></details>';
        });
        if (categories.length) {
            html += `<div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top text-dark"><span class="fw-bolder">TOTAL OUTSTANDING</span><span class="fw-bolder text-danger">${money(grandTotal)}</span></div>`;
            list.innerHTML = html;
            container.classList.remove('d-none');
        } else container.classList.add('d-none');
        return false;
    }

    function showLookupError(message) {
        lookupError.textContent = message;
        lookupError.classList.remove('d-none');
    }

    btnSearch.addEventListener('click', async function () {
        const number = searchInput.value.trim();
        if (!number) { showLookupError('Enter a student number to search.'); return; }
        if (!window.SMS2StudentSearch || !window.SMS2StudentSearch.isCompleteStudentNumber(number)) {
            showLookupError('Enter a complete, valid student number.'); return;
        }
        const version = ++lookupVersion;
        if (lookupController) lookupController.abort();
        lookupController = new AbortController();
        lookupPending = true;
        requestedStudentNumber = number;
        lookupError.textContent = ''; lookupError.classList.add('d-none');
        lockPaymentPanel();
        btnSearch.disabled = true;
        btnSearch.dataset.originalText ||= btnSearch.textContent;
        btnSearch.textContent = 'Searching…';
        try {
            const response = await fetch('../../api/fetch_collection_billing.php?student_number=' + encodeURIComponent(number), {
                credentials: 'same-origin', signal: lookupController.signal
            });
            const data = await response.json();
            if (version !== lookupVersion || searchInput.value.trim() !== number) return;
            if (!response.ok || !data.success) throw new Error(data.message || 'Student record not found.');
            document.getElementById('lblStudentName').textContent = String(data.name || '');
            document.getElementById('lblStudentNo').textContent = String(data.student_number || number);
            document.getElementById('lblCourseYear').textContent = String(data.course_year || '');
            document.getElementById('lblBillingId').textContent = '#' + String(data.billing_id).padStart(5, '0');
            document.getElementById('lblBillingTerm').textContent = String(data.billing_type || 'Assessment');
            document.getElementById('lblAcademicTerm').textContent = [data.academic_year, data.semester].filter(Boolean).join(' • ') || 'Not available';
            document.getElementById('lblTotalAmount').textContent = money(data.total_amount);
            document.getElementById('lblRemainingBalance').textContent = money(data.balance);
            billingId.value = data.billing_id; studentId.value = data.student_id;
            activeBalance = Number(data.balance) || 0; activeStudentNumber = number;

            const hasBreakdownError = appendBreakdown(data.breakdown);
            if (Array.isArray(data.breakdown?.categories)) {
                categorySelect.replaceChildren(new Option('Select unpaid category', ''));
                data.breakdown.categories.forEach(category => {
                    const option = new Option(String(category.category_name || 'Uncategorized') + ' — ' + money(category.category_total), category.category_id);
                    categorySelect.appendChild(option);
                });
            }
            if (hasBreakdownError) throw new Error(String(data.breakdown.error_message || 'Billing information needs review.'));
            billingInfoBox.classList.remove('d-none');
            paymentPanel.style.pointerEvents = 'auto'; paymentPanel.classList.remove('opacity-50');
            inputAmountPaid.value = ''; if (inputCashReceived) inputCashReceived.value = '';
            lblChangeAmount.textContent = '₱ 0.00'; btnProcess.disabled = true;
            updateCategoryContext();
        } catch (error) {
            if (error.name !== 'AbortError' && version === lookupVersion) {
                lockPaymentPanel(); showLookupError(error.message || 'Unable to look up this student. Check your connection and try again.');
            }
        } finally {
            if (version === lookupVersion) { lookupPending = false; btnSearch.disabled = false; btnSearch.textContent = btnSearch.dataset.originalText || 'Search'; }
        }
    });

    searchInput.addEventListener('input', function () {
        if ((lookupPending && searchInput.value.trim() !== requestedStudentNumber) || (activeStudentNumber && searchInput.value.trim() !== activeStudentNumber)) {
            lookupVersion++; if (lookupController) lookupController.abort();
            lookupPending = false;
            btnSearch.disabled = false; btnSearch.textContent = btnSearch.dataset.originalText || 'Search';
            lockPaymentPanel(); lookupError.classList.add('d-none'); lookupError.textContent = '';
        }
    });
    contextSelect.addEventListener('change', updateCategoryContext);
    categorySelect.addEventListener('change', validateAndCompute);

    function validateAndCompute() {
        const applied = Number(inputAmountPaid.value) || 0;
        const cash = Number(inputCashReceived?.value) || 0;
        btnProcess.disabled = true;
        if (!activeStudentNumber || !billingId.value || applied <= 0) {
            lblChangeAmount.textContent = '₱ 0.00'; lblChangeAmount.className = 'fw-bolder fs-5 text-dark'; return;
        }
        if (applied > activeBalance) { lblChangeAmount.textContent = 'Error: Applied amount exceeds balance!'; lblChangeAmount.className = 'fw-bold small text-danger'; return; }
        if (contextSelect.value === 'CATEGORY_PRIORITY' && !categorySelect.value) { lblChangeAmount.textContent = 'Select a specific category.'; lblChangeAmount.className = 'fw-bold small text-danger'; return; }
        if (inputCashReceived && inputCashReceived.value !== '' && cash < applied) { lblChangeAmount.textContent = 'Error: Cash received is insufficient!'; lblChangeAmount.className = 'fw-bold small text-danger'; return; }
        lblChangeAmount.textContent = money(Math.max(0, cash - applied));
        lblChangeAmount.className = 'fw-bolder fs-5 ' + (cash > applied ? 'text-success' : 'text-dark');
        btnProcess.disabled = false;
    }
    inputAmountPaid.addEventListener('input', validateAndCompute);
    if (inputCashReceived) inputCashReceived.addEventListener('input', validateAndCompute);
    if (prefillStudent) { searchInput.value = prefillStudent; btnSearch.click(); }

    const paymentForm = document.getElementById('paymentForm');
    const reviewModalElement = document.getElementById('paymentReviewModal');
    const confirmPaymentButton = document.getElementById('btnConfirmPayment');
    if (paymentForm && reviewModalElement && confirmPaymentButton && window.bootstrap) {
        const reviewModal = new bootstrap.Modal(reviewModalElement);
        paymentForm.addEventListener('submit', function (event) {
            if (paymentForm.dataset.confirmed === '1') return;
            event.preventDefault();
            if (!activeStudentNumber || !billingId.value || btnProcess.disabled) return;
            const applied = Number(inputAmountPaid.value) || 0;
            const cash = Number(inputCashReceived?.value) || applied;
            let context = 'General / Full Payment';
            if (contextSelect.value === 'ENROLLMENT_PRIORITY') context = 'Enrollment Priority';
            if (contextSelect.value === 'CATEGORY_PRIORITY') context = categorySelect.options[categorySelect.selectedIndex]?.text || 'Designated Category';
            document.getElementById('reviewStudent').textContent = document.getElementById('lblStudentName').textContent + ' (' + document.getElementById('lblStudentNo').textContent + ')';
            document.getElementById('reviewContext').textContent = context;
            document.getElementById('reviewBalance').textContent = money(activeBalance);
            document.getElementById('reviewAmount').textContent = money(applied);
            document.getElementById('reviewCash').textContent = money(cash);
            document.getElementById('reviewChange').textContent = money(cash - applied);
            document.getElementById('reviewCashier').textContent = paymentForm.dataset.cashierName || 'Authenticated cashier';
            reviewModal.show();
        });
        confirmPaymentButton.addEventListener('click', function () {
            confirmPaymentButton.disabled = true;
            confirmPaymentButton.textContent = 'Processing…';
            btnProcess.disabled = true; paymentForm.dataset.confirmed = '1'; paymentForm.submit();
        });
        reviewModalElement.addEventListener('hidden.bs.modal', function () {
            if (paymentForm.dataset.confirmed !== '1') confirmPaymentButton.disabled = false;
        });
    }
    updateCategoryContext();
});
