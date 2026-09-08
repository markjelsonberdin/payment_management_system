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

    function isCompleteStudentNumber(value) {
        return window.SMS2StudentSearch
            ? window.SMS2StudentSearch.isCompleteStudentNumber(value)
            : /^S\d{9}$/i.test(value.trim());
    }

    function lookupStudent() {
        let sn = studentInput.value.trim();
        if (!isCompleteStudentNumber(sn)) {
            hintDisplay.innerHTML = '';
            return;
        }

        fetch('../../api/search-student.php?student_number=' + encodeURIComponent(sn))
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            hintDisplay.innerHTML = '<i class="ti ti-circle-check text-success me-1"></i> <span class="fw-bold text-success">' + data.name + '</span>';
                        } else {
                            hintDisplay.innerHTML = '<i class="ti ti-circle-x text-danger me-1"></i> <span class="text-danger">Student not found</span>';
                        }
                    })
                    .catch(() => {
                        hintDisplay.innerHTML = '';
                    });
    }

    if (studentInput) {
        studentInput.addEventListener('input', function () {
            hintDisplay.innerHTML = '';
            if (isCompleteStudentNumber(this.value)) {
                lookupStudent();
            }
        });
        studentInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                lookupStudent();
            }
        });
    }
});