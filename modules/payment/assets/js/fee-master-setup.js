document.addEventListener('DOMContentLoaded', function () {
    // Populate Edit Modal
    document.querySelectorAll('.btn-edit-trigger').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('editFeeId').value = this.dataset.id;
            document.getElementById('editFeeName').value = this.dataset.name;
            document.getElementById('editFeeCategory').value = this.dataset.category;
            document.getElementById('editFeeAmount').value = this.dataset.amount;
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
            document.getElementById('archiveCategoryId').value = this.dataset.categoryId;
            document.getElementById('archiveCategoryName').textContent = this.dataset.categoryName;
            document.getElementById('archiveCategoryCount').textContent = this.dataset.itemCount;
        });
    });
});
