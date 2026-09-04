document.addEventListener("DOMContentLoaded", function () {
    const btnSearch = document.getElementById('btnSearchBilling');
    const searchInput = document.getElementById('searchStudentDiscount');
    const panel = document.getElementById('scholarshipPanel');
    const detailsContainer = document.getElementById('billingDetailsContainer');
    const btnSubmit = document.getElementById('btnSubmitDiscount');
    const searchHint = document.getElementById('discountSearchHint');
    const fetchUrl = (window.SMS2_DISCOUNT && window.SMS2_DISCOUNT.fetchUrl)
        ? window.SMS2_DISCOUNT.fetchUrl
        : '../../api/fetch_unpaid_billing.php';

    let currentBalance = 0;
    let searchAbort = null;

    function lockPanel() {
        if (!panel) return;
        panel.style.pointerEvents = 'none';
        panel.classList.add('opacity-50');
        if (btnSubmit) btnSubmit.disabled = true;
    }

    function unlockPanel() {
        if (!panel) return;
        panel.style.pointerEvents = 'auto';
        panel.classList.remove('opacity-50');
    }

    function setHint(message, isError) {
        if (!searchHint) return;
        searchHint.textContent = message;
        searchHint.classList.toggle('text-danger', !!isError);
        searchHint.classList.toggle('text-muted', !isError);
    }

    function setSearching(isSearching) {
        if (!btnSearch) return;
        btnSearch.disabled = isSearching;
        btnSearch.textContent = isSearching ? 'Searching…' : 'Search';
        if (searchInput) searchInput.disabled = isSearching;
    }

    function searchBilling() {
        if (!searchInput) return;
        const sn = searchInput.value.trim();
        if (sn === '') {
            setHint('Enter a student number to search.', true);
            return;
        }

        if (searchAbort) {
            searchAbort.abort();
        }
        searchAbort = new AbortController();
        const timeoutId = window.setTimeout(function () {
            searchAbort.abort();
        }, 15000);

        setSearching(true);
        setHint('Looking up unpaid billing…', false);

        fetch(fetchUrl + '?student_number=' + encodeURIComponent(sn), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: searchAbort.signal
        })
            .then(function (res) {
                return res.text().then(function (text) {
                    let data = null;
                    try {
                        data = text ? JSON.parse(text) : null;
                    } catch (err) {
                        throw new Error('The billing lookup did not return valid data. Please try again.');
                    }
                    if (!res.ok) {
                        throw new Error((data && data.message) || 'Unable to search billing right now.');
                    }
                    return data || { success: false };
                });
            })
            .then(function (data) {
                if (data.success) {
                    document.getElementById('dispStudentName').textContent = data.name;
                    document.getElementById('dispStudentNumber').textContent = data.student_number;
                    document.getElementById('dispBillingId').textContent = '#' + data.billing_id.toString().padStart(5, '0');
                    document.getElementById('dispOriginalBalance').textContent = '₱ ' + data.balance.toLocaleString('en-US', { minimumFractionDigits: 2 });

                    document.getElementById('inputStudentNumber').value = data.student_number;
                    document.getElementById('inputBillingId').value = data.billing_id;
                    currentBalance = data.balance;

                    detailsContainer.classList.remove('d-none');
                    unlockPanel();
                    resetSelection();
                    setHint('Select a scholarship or discount on the right, then apply it.', false);
                } else {
                    detailsContainer.classList.add('d-none');
                    lockPanel();
                    setHint(data.message || 'Student not found or no unpaid balance.', true);
                }
            })
            .catch(function (err) {
                detailsContainer.classList.add('d-none');
                lockPanel();
                if (err && err.name === 'AbortError') {
                    setHint('Search timed out. Check the student number and try again.', true);
                    return;
                }
                setHint((err && err.message) || 'Search failed. Please try again.', true);
            })
            .finally(function () {
                window.clearTimeout(timeoutId);
                setSearching(false);
            });
    }

    if (btnSearch) {
        btnSearch.addEventListener('click', searchBilling);
    }

    if (searchInput) {
        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchBilling();
            }
        });
    }

    const cards = document.querySelectorAll('.scholarship-card');

    cards.forEach(function (card) {
        card.addEventListener('click', function () {
            cards.forEach(function (c) { c.classList.remove('selected'); });
            this.classList.add('selected');

            const sName = this.getAttribute('data-name');
            const sType = this.getAttribute('data-type');
            const sVal = parseFloat(this.getAttribute('data-value'));

            document.getElementById('inputScholarshipName').value = sName;
            document.getElementById('inputDiscountType').value = sType;
            document.getElementById('inputDiscountValue').value = sVal;

            let discountAmount = 0;
            if (sType === 'Percentage') {
                discountAmount = currentBalance * (sVal / 100);
            } else {
                discountAmount = sVal;
            }

            if (discountAmount > currentBalance) discountAmount = currentBalance;
            const newBalance = currentBalance - discountAmount;

            document.getElementById('dispComputedDiscount').textContent = '- ₱ ' + discountAmount.toLocaleString('en-US', { minimumFractionDigits: 2 });
            document.getElementById('dispNewBalance').textContent = '₱ ' + newBalance.toLocaleString('en-US', { minimumFractionDigits: 2 });
            document.getElementById('inputComputedAmount').value = discountAmount;

            if (btnSubmit) btnSubmit.disabled = false;
        });
    });

    function resetSelection() {
        cards.forEach(function (c) { c.classList.remove('selected'); });
        document.getElementById('dispComputedDiscount').textContent = '- ₱ 0.00';
        document.getElementById('dispNewBalance').textContent = '₱ ' + currentBalance.toLocaleString('en-US', { minimumFractionDigits: 2 });
        if (btnSubmit) btnSubmit.disabled = true;
    }
});
