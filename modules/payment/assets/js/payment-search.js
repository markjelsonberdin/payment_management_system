/**
 * SMS 2 - Payment Search
 * Provides table filtering and completed student-number search triggers.
 */
document.addEventListener("DOMContentLoaded", function () {
    const searchInputs = document.querySelectorAll(".table-live-search-input");

    searchInputs.forEach(function (input) {
        input.addEventListener("input", function (event) {
            const query = event.target.value.toLowerCase();
            const targetSelector = input.getAttribute("data-table-target");
            const table = targetSelector
                ? document.querySelector(targetSelector)
                : document.querySelector(".live-search-table");

            if (!table) return;

            const tbody = table.querySelector("tbody");
            if (!tbody) return;

            const existingNoResults = tbody.querySelector(".live-search-no-results");
            if (existingNoResults) {
                existingNoResults.remove();
            }

            const rows = tbody.querySelectorAll("tr");
            let hasVisibleRow = false;

            rows.forEach(function (row) {
                if (row.classList.contains("empty-state-row")) {
                    return;
                }

                const isMatch = row.textContent.toLowerCase().includes(query);
                row.style.display = isMatch ? "" : "none";
                hasVisibleRow = hasVisibleRow || isMatch;
            });

            if (!hasVisibleRow && rows.length > 0 && !tbody.querySelector(".empty-state-row")) {
                const row = document.createElement("tr");
                const cell = document.createElement("td");
                const columns = rows[0].children.length || 1;

                row.className = "live-search-no-results text-center py-4";
                cell.colSpan = columns;
                cell.className = "text-muted py-5";
                cell.innerHTML = '<i class="ti ti-search fs-3 mb-2 d-block"></i>';
                cell.append("No matching records found for ");

                const queryLabel = document.createElement("strong");
                queryLabel.textContent = `"${query}"`;
                cell.append(queryLabel);

                row.appendChild(cell);
                tbody.appendChild(row);
            }
        });
    });

    function isCompleteStudentNumber(value) {
        // Existing payment-module records and field examples use S + 9 digits.
        return /^S\d{9}$/i.test(value.trim());
    }

    window.SMS2StudentSearch = {
        isCompleteStudentNumber: isCompleteStudentNumber
    };

    function setupAutoSearch(inputId, buttonId) {
        const input = document.getElementById(inputId);
        const button = document.getElementById(buttonId);

        if (!input || !button) return;

        input.addEventListener("input", function () {
            // Typing, pausing, pasting, and backspacing must not trigger a lookup.
            // The page-specific handler performs the lookup on an explicit action.
            if (!input.value.trim()) {
                input.dispatchEvent(new CustomEvent('sms2:student-search-cleared'));
            }
        });

        input.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                if (isCompleteStudentNumber(input.value)) {
                    button.click();
                }
            }
        });
    }

    setupAutoSearch("searchStudentNumber", "btnSearchStudent");
    setupAutoSearch("searchStudentDiscount", "btnSearchBilling");
});
