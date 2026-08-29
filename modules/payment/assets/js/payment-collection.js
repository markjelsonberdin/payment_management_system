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

        fetch('../../api/fetch_collection_billing.php?student_number=' + sn)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Fill UI labels
                    document.getElementById('lblStudentName').textContent = data.name;
                    document.getElementById('lblStudentNo').textContent = data.student_number;
                    document.getElementById('lblCourseYear').textContent = data.course_year;
                    document.getElementById('lblBillingId').textContent = '#' + data.billing_id.toString().padStart(5, '0');
                    document.getElementById('lblBillingTerm').textContent = data.billing_type;
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
                                    <i class="fas fa-exclamation-triangle me-1"></i> ${data.breakdown.error_message}
                                </div>
                            `;
                            breakdownContainer.classList.remove('d-none');
                            
                            // Prevent payment processing because of inconsistency
                            paymentPanel.style.pointerEvents = 'none';
                            paymentPanel.classList.add('opacity-50');
                            alert(data.breakdown.error_message);
                            return;
                        } else if (data.breakdown.categories && data.breakdown.categories.length > 0) {
                            let html = '';
                            let grandTotal = 0;
                            
                            data.breakdown.categories.forEach(cat => {
                                html += `<div class="mb-3">
                                            <div class="fw-bold text-dark mb-1 border-bottom pb-1" style="font-size: 0.85rem;">${cat.category_name}</div>`;
                                
                                cat.fees.forEach(fee => {
                                    let sourceBadge = '';
                                    if (fee.source_context) {
                                        let badgeColor = fee.source_context === 'Enrollment Assessment' ? 'bg-info' : 'bg-secondary';
                                        sourceBadge = `<span class="badge ${badgeColor} text-white ms-2" style="font-size: 0.65rem;">${fee.source_context}</span>`;
                                    }
                                    
                                    html += `<div class="d-flex justify-content-between align-items-center mb-1 text-muted" style="font-size: 0.8rem;">
                                                <div>
                                                    <span class="fw-medium">${fee.fee_name}</span>
                                                    ${sourceBadge}
                                                </div>
                                                <span class="fw-bold text-dark">₱ ${parseFloat(fee.remaining_amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}</span>
                                             </div>`;
                                });
                                
                                html += `<div class="d-flex justify-content-between align-items-center mt-1 text-dark">
                                            <span class="small fst-italic">Category Total</span>
                                            <span class="fw-bold small">₱ ${parseFloat(cat.category_total).toLocaleString('en-US', { minimumFractionDigits: 2 })}</span>
                                         </div>
                                       </div>`;
                                grandTotal += cat.category_total;
                            });
                            
                            html += `<div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top text-dark">
                                        <span class="fw-bolder">BREAKDOWN TOTAL</span>
                                        <span class="fw-bolder text-danger">₱ ${grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2 })}</span>
                                     </div>`;
                                     
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