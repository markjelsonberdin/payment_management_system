document.addEventListener("DOMContentLoaded", function () {
    const btnSearch = document.getElementById('btnSearchBilling');
    const searchInput = document.getElementById('searchStudentDiscount');
    const panel = document.getElementById('scholarshipPanel');
    const detailsContainer = document.getElementById('billingDetailsContainer');
    const btnSubmit = document.getElementById('btnSubmitDiscount');

    let currentBalance = 0;

    // 1. Search Student Billing Logic
    btnSearch.addEventListener('click', function () {
        let sn = searchInput.value.trim();
        if (sn === '') return;

        fetch('../../api/fetch_unpaid_billing.php?student_number=' + sn)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // I-populate ang UI
                    document.getElementById('dispStudentName').textContent = data.name;
                    document.getElementById('dispStudentNumber').textContent = data.student_number;
                    document.getElementById('dispBillingId').textContent = '#' + data.billing_id.toString().padStart(5, '0');
                    document.getElementById('dispOriginalBalance').textContent = '₱ ' + data.balance.toLocaleString('en-US', { minimumFractionDigits: 2 });

                    // I-set ang hidden inputs
                    document.getElementById('inputStudentNumber').value = data.student_number;
                    document.getElementById('inputBillingId').value = data.billing_id;
                    currentBalance = data.balance;

                    // I-unlock ang kanang panel
                    detailsContainer.classList.remove('d-none');
                    panel.style.pointerEvents = 'auto';
                    panel.classList.remove('opacity-50');

                    resetSelection();
                } else {
                    alert(data.message || "Student not found or no unpaid balance.");
                    detailsContainer.classList.add('d-none');
                    panel.style.pointerEvents = 'none';
                    panel.classList.add('opacity-50');
                }
            });
    });

    // 2. Scholarship Card Selection Logic
    const cards = document.querySelectorAll('.scholarship-card');

    cards.forEach(card => {
        card.addEventListener('click', function () {
            // Remove 'selected' class sa lahat, tapos i-add sa pinindot
            cards.forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');

            // Kunin ang data attributes mula sa card
            let sName = this.getAttribute('data-name');
            let sType = this.getAttribute('data-type');
            let sVal = parseFloat(this.getAttribute('data-value'));

            // I-set sa hidden form inputs
            document.getElementById('inputScholarshipName').value = sName;
            document.getElementById('inputDiscountType').value = sType;
            document.getElementById('inputDiscountValue').value = sVal;

            // 3. Compute ng Discount!
            let discountAmount = 0;
            if (sType === 'Percentage') {
                discountAmount = currentBalance * (sVal / 100);
            } else {
                discountAmount = sVal;
            }

            // Wag pababain sa zero ang final balance
            if (discountAmount > currentBalance) discountAmount = currentBalance;
            let newBalance = currentBalance - discountAmount;

            // I-update ang UI text at hidden input
            document.getElementById('dispComputedDiscount').textContent = '- ₱ ' + discountAmount.toLocaleString('en-US', { minimumFractionDigits: 2 });
            document.getElementById('dispNewBalance').textContent = '₱ ' + newBalance.toLocaleString('en-US', { minimumFractionDigits: 2 });
            document.getElementById('inputComputedAmount').value = discountAmount;

            // I-enable ang submit button
            btnSubmit.disabled = false;
        });
    });

    function resetSelection() {
        cards.forEach(c => c.classList.remove('selected'));
        document.getElementById('dispComputedDiscount').textContent = '- ₱ 0.00';
        document.getElementById('dispNewBalance').textContent = '₱ ' + currentBalance.toLocaleString('en-US', { minimumFractionDigits: 2 });
        btnSubmit.disabled = true;
    }
});