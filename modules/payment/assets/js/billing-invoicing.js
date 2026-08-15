document.addEventListener("DOMContentLoaded", function () {

    // ==========================================
    // 1. AUTO-COMPUTE TOTAL AMOUNT LOGIC
    // ==========================================
    const checkboxes = document.querySelectorAll('.fee-checkbox');
    const totalDisplay = document.getElementById('totalComputedAmount');

    function updateTotal() {
        let total = 0;
        checkboxes.forEach(box => {
            if (box.checked) {
                total += parseFloat(box.getAttribute('data-amount'));
            }
        });
        totalDisplay.innerHTML = '₱ ' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    checkboxes.forEach(box => {
        box.addEventListener('change', updateTotal);
    });

    updateTotal();


    // ==========================================
    // 2. LIVE SEARCH STUDENT NAME LOGIC
    // ==========================================
    const studentInput = document.getElementById('studentSearchInput');
    const hintDisplay = document.getElementById('studentNameHint');

    if (studentInput) {
        studentInput.addEventListener('input', function () {
            let sn = this.value.trim();

            if (sn.length >= 3) {
                fetch('../../api/search-student.php?student_number=' + sn)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            hintDisplay.innerHTML = '<i class="fas fa-check-circle text-success me-1"></i> <span class="fw-bold text-success">' + data.name + '</span>';
                        } else {
                            hintDisplay.innerHTML = '<i class="fas fa-times-circle text-danger me-1"></i> <span class="text-danger">Student not found</span>';
                        }
                    })
                    .catch(() => {
                        hintDisplay.innerHTML = '';
                    });
            } else {
                hintDisplay.innerHTML = '';
            }
        });
    }
});