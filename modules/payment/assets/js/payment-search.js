/**
 * SMS 2 - Payment Search
 * Provides table filtering and debounced student search triggers.
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
                cell.innerHTML = '<i class="fas fa-search fs-3 mb-2 d-block"></i>';
                cell.append("No matching records found for ");

                const queryLabel = document.createElement("strong");
                queryLabel.textContent = `"${query}"`;
                cell.append(queryLabel);

                row.appendChild(cell);
                tbody.appendChild(row);
            }
        });
    });

    function setupAutoSearch(inputId, buttonId) {
        const input = document.getElementById(inputId);
        const button = document.getElementById(buttonId);

        if (!input || !button) return;

        let timeout = null;
        input.addEventListener("input", function () {
            clearTimeout(timeout);
            timeout = setTimeout(function () {
                const valueLength = input.value.trim().length;
                if (valueLength >= 3 || valueLength === 0) {
                    button.click();
                }
            }, 500);
        });
    }

    setupAutoSearch("searchStudentNumber", "btnSearchStudent");
    setupAutoSearch("searchStudentDiscount", "btnSearchBilling");
});
