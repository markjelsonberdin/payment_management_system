document.addEventListener('DOMContentLoaded', function () {
    // Populate Edit Modal
    document.querySelectorAll('.btn-edit-trigger').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('editFeeId').value = this.dataset.id;
            document.getElementById('editFeeName').value = this.dataset.name;
            document.getElementById('editFeeType').value = this.dataset.type;
            document.getElementById('editFeeAmount').value = this.dataset.amount;
            document.getElementById('editFeePriority').value = this.dataset.priority;
            document.getElementById('editFeeRequired').value = this.dataset.required;
        });
    });

    // Populate Archive Modal
    document.querySelectorAll('.btn-archive-trigger').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('archiveFeeId').value = this.dataset.feeId;
            document.getElementById('archiveFeeName').textContent = this.dataset.feeName;
        });
    });

    // Populate Archive Category Modal
    document.querySelectorAll('.btn-archive-category-trigger').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('archiveCategoryType').value = this.dataset.feeType;
            document.getElementById('archiveCategoryName').textContent = this.dataset.feeType;
            document.getElementById('archiveCategoryCount').textContent = this.dataset.itemCount;
        });
    });
});