<?php
/**
 * SMS 2 - Breadcrumb Renderer
 *
 * @param array $breadcrumbs Array of ['label' => string, 'url' => string|null]
 */
function renderBreadcrumbs(array $breadcrumbs): void
{
    if (empty($breadcrumbs)) {
        return;
    }
    ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="<?= BASE_URL ?>/dashboard/index.php"><i class="fas fa-home"></i></a>
            </li>
            <?php foreach ($breadcrumbs as $index => $crumb): ?>
                <?php if ($index === array_key_last($breadcrumbs)): ?>
                    <li class="breadcrumb-item active" aria-current="page">
                        <?= htmlspecialchars($crumb['label']) ?>
                    </li>
                <?php else: ?>
                    <li class="breadcrumb-item">
                        <?php if (!empty($crumb['url'])): ?>
                            <a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['label']) ?></a>
                        <?php else: ?>
                            <?= htmlspecialchars($crumb['label']) ?>
                        <?php endif; ?>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ol>
    </nav>
    <?php
}
