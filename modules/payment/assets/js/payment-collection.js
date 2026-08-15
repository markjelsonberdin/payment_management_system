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