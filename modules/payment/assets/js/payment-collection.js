document.addEventListener("DOMContentLoaded", function () {
    const btnSearch = document.getElementById('btnSearchStudent');
    const searchInput = document.getElementById('searchStudentNumber');
    const billingInfoBox = document.getElementById('studentBillingInfo');
    const paymentPanel = document.getElementById('paymentPanel');
    const inputAmountPaid = document.getElementById('inputAmountPaid');
    const lblChangeAmount = document.getElementById('lblChangeAmount');
    const btnProcess = document.getElementById('btnProcessPayment');

    let activeBalance = 0;

    btnSearch.addEventListener('click', function () {
        let sn = searchInput.value.trim();
        if (!sn) return;
        if (!window.SMS2StudentSearch || !window.SMS2StudentSearch.isCompleteStudentNumber(sn)) {
            return;
        }

        fetch('../../api/fetch_collection_billing.php?student_number=' + sn)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Fill UI labels
                    document.getElementById('lblStudentName').textContent = data.name;
                    document.getElementById('lblStudentNo').textContent = data.student_number;
                    document.getElementById('lblCourseYear').textContent = data.course_year;
                    document.getElementById('lblBillingId').textContent = '#' + data.billing_id.toString().padStart(5, '0');
                    document.getElementById('lblBillingTerm').textContent = data.billing_type || 'Assessment';
                    document.getElementById('lblAcademicTerm').textContent = [data.academic_year, data.semester].filter(Boolean).join(' • ') || 'Not available';
                    document.getElementById('lblTotalAmount').textContent = '₱ ' + data.total_amount.toLocaleString('en-US', { minimumFractionDigits: 2 });
                    document.getElementById('lblRemainingBalance').textContent = '₱ ' + data.balance.toLocaleString('en-US', { minimumFractionDigits: 2 });

                    // Set hidden inputs
                    document.getElementById('inputBillingId').value = data.billing_id;
                    document.getElementById('inputStudentId').value = data.student_id;
                    activeBalance = data.balance;

                    // Unpaid Fees Breakdown Logic
                    const breakdownContainer = document.getElementById('unpaidFeesContainer');
                    const breakdownList = document.getElementById('unpaidFeesList');
                    breakdownList.innerHTML = '';

                    if (data.breakdown) {
                        if (data.breakdown.has_error) {
                            // Show consistency error
                            breakdownList.innerHTML = `
                                <div class="alert alert-danger p-2 small mb-0">
                                    <i class="ti ti-alert-triangle me-1"></i> ${data.breakdown.error_message}
                                </div>
                            `;
                            breakdownContainer.classList.remove('d-none');
                            
                            // Prevent payment processing because of inconsistency
                            paymentPanel.style.pointerEvents = 'none';
                            paymentPanel.classList.add('opacity-50');
                            alert(data.breakdown.error_message);
                            return;
                        } else if (data.breakdown.categories && data.breakdown.categories.length > 0) {
                            const categorySelect = document.getElementById('inputCategoryId');
                            if (categorySelect) {
                                categorySelect.innerHTML = '<option value="">Select unpaid category</option>';
                                data.breakdown.categories.forEach(cat => {
                                    const option = document.createElement('option');
                                    option.value = cat.category_id;
                                    option.textContent = `${cat.category_name} — ₱ ${parseFloat(cat.category_total).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
                                    categorySelect.appendChild(option);
                                });
                            }
                            let html = '';
                            let grandTotal = 0;
                            data.breakdown.categories.forEach(cat => {
                                const categoryTotal = Number(cat.category_total || 0);
                                grandTotal += categoryTotal;
                                html += `<details class="border rounded mb-2 bg-white"><summary class="d-flex justify-content-between align-items-center px-2 py-2 fw-bold" style="cursor:pointer"><span>${cat.category_name}</span><span class="text-danger">₱ ${categoryTotal.toLocaleString('en-US', { minimumFractionDigits: 2 })}</span></summary><div class="border-top px-2 pt-2 pb-1">`;
                                cat.fees.forEach(fee => {
                                    const source = fee.source_context ? `<span class="badge bg-light text-secondary border ms-1">${fee.source_context}</span>` : '';
                                    html += `<div class="d-flex justify-content-between gap-2 align-items-start mb-2 small"><span>${fee.fee_name}${source}</span><strong>₱ ${Number(fee.remaining_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}</strong></div>`;
                                });
                                html += `</div></details>`;
                            });
                            html += `<div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top text-dark"><span class="fw-bolder">TOTAL OUTSTANDING</span><span class="fw-bolder text-danger">₱ ${grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2 })}</span></div>`;
                            breakdownList.innerHTML = html;
                            breakdownContainer.classList.remove('d-none');
                        } else {
                            breakdownContainer.classList.add('d-none');
                        }
                    } else {
                        breakdownContainer.classList.add('d-none');
                    }

                    // Unlock right panel
                    billingInfoBox.classList.remove('d-none');
                    paymentPanel.style.pointerEvents = 'auto';
                    paymentPanel.classList.remove('opacity-50');

                    inputAmountPaid.value = '';
                    lblChangeAmount.textContent = '₱ 0.00';
                    btnProcess.disabled = true;
                } else {
                    alert(data.message || "Student record not found.");
                    billingInfoBox.classList.add('d-none');
                    paymentPanel.style.pointerEvents = 'none';
                    paymentPanel.classList.add('opacity-50');
                }
            });
    });

    const inputCashReceived = document.getElementById('inputCashReceived');

    // Validation & Change Computation
    function validateAndCompute() {
        let applied = parseFloat(inputAmountPaid.value) || 0;
        let cash = parseFloat(inputCashReceived.value) || 0;

        // Reset state
        btnProcess.disabled = true;
        
        if (applied <= 0) {
            lblChangeAmount.textContent = '₱ 0.00';
            lblChangeAmount.className = 'fw-bolder fs-5 text-dark';
            return;
        }

        // 1. Validate Amount Applied against Balance
        if (applied > activeBalance) {
            lblChangeAmount.textContent = 'Error: Applied amount exceeds balance!';
            lblChangeAmount.className = 'fw-bold small text-danger';
            return;
        }

        // 2. Compute Change if Cash is provided
        if (inputCashReceived.value !== '') {
            if (cash < applied) {
                lblChangeAmount.textContent = 'Error: Cash received is insufficient!';
                lblChangeAmount.className = 'fw-bold small text-danger';
                return;
            }
            let change = cash - applied;
            lblChangeAmount.textContent = '₱ ' + change.toLocaleString('en-US', { minimumFractionDigits: 2 });
            lblChangeAmount.className = 'fw-bolder fs-5 text-success';
        } else {
            lblChangeAmount.textContent = '₱ 0.00';
            lblChangeAmount.className = 'fw-bolder fs-5 text-dark';
        }

        // All good
        btnProcess.disabled = false;
    }

    inputAmountPaid.addEventListener('input', validateAndCompute);
    if (inputCashReceived) {
        inputCashReceived.addEventListener('input', validateAndCompute);
    }
});

    // The browser only presents a review; PHP remains authoritative for validation and allocation.
    const paymentForm = document.getElementById('paymentForm');
    const reviewModalElement = document.getElementById('paymentReviewModal');
    const confirmPaymentButton = document.getElementById('btnConfirmPayment');
    if (paymentForm && reviewModalElement && confirmPaymentButton && window.bootstrap) {
        const reviewModal = new bootstrap.Modal(reviewModalElement);
        const money = value => '₱ ' + (Number(value) || 0).toLocaleString('en-US', { minimumFractionDigits: 2 });
        paymentForm.addEventListener('submit', function (event) {
            if (paymentForm.dataset.confirmed === '1') return;
            event.preventDefault();
            const applied = Number(inputAmountPaid.value) || 0;
            const cash = Number(inputCashReceived.value) || applied;
            const selectedCategory = document.getElementById('inputCategoryId');
            const context = selectedCategory && selectedCategory.value ? selectedCategory.options[selectedCategory.selectedIndex].text : 'General allocation';
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
            confirmPaymentButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';
            btnProcess.disabled = true;
            paymentForm.dataset.confirmed = '1';
            paymentForm.submit();
        });
        reviewModalElement.addEventListener('hidden.bs.modal', function () {
            if (paymentForm.dataset.confirmed !== '1') confirmPaymentButton.disabled = false;
        });
    }