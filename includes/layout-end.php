<?php
/**
 * SMS 2 - Authenticated Layout End
 */
?>
        </main>
        <?php require_once ROOT_PATH . '/includes/footer.php'; ?>
    </div>
</div>
<?php require_once ROOT_PATH . '/includes/scripts.php'; ?>
<?php if (($activePage ?? '') === 'accounting/student-billing-invoicing'): ?>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/bulk-dashboard-context.js"></script>
<?php endif; ?>
